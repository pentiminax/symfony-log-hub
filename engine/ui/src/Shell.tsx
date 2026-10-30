import { useQuery } from "@tanstack/react-query";
import { NavLink, Outlet, useLocation, useSearchParams } from "react-router";
import { Button } from "@/components/ui/button";
import { DEFAULT_RANGE, RANGES } from "@/lib/range";
import { cn } from "@/lib/utils";
import { type Project, api, clearKey } from "./api";
import { Icon } from "./icons";

// Order is part of the product: Overview → Traces → Logs → Projects.
const nav = [
  { to: "/", label: "Overview", icon: "overview", end: true },
  { to: "/traces", label: "Traces", icon: "traces" },
  { to: "/logs", label: "Logs", icon: "logs" },
  { to: "/projects", label: "Projects", icon: "projects" },
] as const;

const link = "flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-body text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground aria-[current=page]:bg-sidebar-accent aria-[current=page]:font-medium aria-[current=page]:text-sidebar-accent-foreground";

export function Shell() {
  const [params, setParams] = useSearchParams();
  const { pathname } = useLocation();
  const project = params.get("project") ?? "";
  const range = params.get("range") ?? DEFAULT_RANGE;
  const projects = useQuery({ queryKey: ["projects"], queryFn: () => api<Project[]>("/v1/projects") });
  // Project and period travel with the user from page to page.
  const search = new URLSearchParams([...params].filter(([k]) => ["project", "range", "from", "to"].includes(k))).toString();
  const to = (path: string) => ({ pathname: path, search: path === "/projects" ? search.replace(/(^|&)(range|from|to)=[^&]*/g, "") : search });

  const setParam = (k: string, v: string, empty = "") =>
    setParams((prev) => {
      const next = new URLSearchParams(prev);
      if (v && v !== empty) next.set(k, v);
      else next.delete(k);
      next.delete("cursor");
      return next;
    });

  const showPeriod = !/^\/(projects|traces\/)/.test(pathname);

  return (
    <div className="flex h-dvh overflow-hidden bg-background">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-body"
      >
        Skip to content
      </a>

      <aside className="hidden h-dvh w-sidebar shrink-0 flex-col border-r border-sidebar-border bg-sidebar md:flex">
        <div className="flex items-center gap-2.5 px-4 py-4">
          <span className="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
            <Icon name="logs" />
          </span>
          <span className="leading-tight">
            <span className="block text-md font-semibold tracking-tight">LogHub</span>
            <span className="block text-micro text-muted-foreground">Logs, traces, spans</span>
          </span>
        </div>
        <nav className="flex flex-1 flex-col gap-0.5 px-2" aria-label="Sections">
          {nav.map((n) => (
            <NavLink key={n.to} to={to(n.to)} end={"end" in n} className={link}>
              <Icon name={n.icon} /> {n.label}
            </NavLink>
          ))}
        </nav>
        <div className="border-t border-sidebar-border p-2">
          <Button variant="ghost" className="w-full justify-start gap-2.5" onClick={clearKey}>
            <Icon name="sign-out" /> Forget master key
          </Button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex flex-wrap items-center gap-2 border-b border-border bg-background px-4 py-2">
          <span className="mr-auto flex items-center gap-2 md:hidden">
            <span className="flex size-6 items-center justify-center rounded bg-primary text-primary-foreground">
              <Icon name="logs" className="size-3.5" />
            </span>
            <span className="text-body font-semibold tracking-tight">LogHub</span>
          </span>
          <span className="mr-auto hidden md:block" />
          <label htmlFor="project-select" className="sr-only">Project</label>
          <select id="project-select" className="lh-control min-w-36 max-w-full" value={project} onChange={(e) => setParam("project", e.target.value)}>
            <option value="">All projects</option>
            {projects.data?.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
          {showPeriod && (
            <>
              <label htmlFor="period-select" className="sr-only">Time range</label>
              <select id="period-select" className="lh-control" value={range} onChange={(e) => setParam("range", e.target.value, DEFAULT_RANGE)}>
                {RANGES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
              {range === "custom" && (
                <>
                  <input type="datetime-local" className="lh-control" aria-label="From" value={params.get("from") ?? ""} onChange={(e) => setParam("from", e.target.value)} />
                  <input type="datetime-local" className="lh-control" aria-label="To" value={params.get("to") ?? ""} onChange={(e) => setParam("to", e.target.value)} />
                </>
              )}
            </>
          )}
          <Button variant="ghost" size="icon" className="md:hidden" onClick={clearKey}>
            <Icon name="sign-out" />
            <span className="sr-only">Forget master key</span>
          </Button>
        </header>

        <main id="main" className="min-h-0 min-w-0 flex-1 overflow-y-auto pb-[calc(4.25rem+env(safe-area-inset-bottom))] md:pb-0">
          <Outlet />
        </main>

        <nav
          className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t border-border bg-background pb-[env(safe-area-inset-bottom)] md:hidden"
          aria-label="Sections (mobile)"
        >
          {nav.map((n) => (
            <NavLink
              key={n.to}
              to={to(n.to)}
              end={"end" in n}
              className={cn("flex min-h-14 flex-col items-center justify-center gap-0.5 text-micro text-muted-foreground aria-[current=page]:font-medium aria-[current=page]:text-foreground")}
            >
              <Icon name={n.icon} className="size-5" />
              {n.label}
            </NavLink>
          ))}
        </nav>
      </div>
    </div>
  );
}
