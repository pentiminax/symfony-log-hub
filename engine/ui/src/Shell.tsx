import { useQuery } from "@tanstack/react-query";
import { useEffect, useRef } from "react";
import { Navigate, NavLink, Outlet, useLocation, useNavigate, useSearchParams } from "react-router";
import { Button } from "@/components/ui/button";
import { DEFAULT_RANGE, RANGES } from "@/lib/range";
import { cn } from "@/lib/utils";
import { type Project, api, clearKey } from "./api";
import { Icon } from "./icons";

/** Native select dressed as the design's outline button: leading icon, 36px tall. */
export function OutlineSelect({ icon, className, children, ...props }: { icon: "calendar" | "chevrons-up-down" } & React.ComponentProps<"select">) {
  return (
    <span className="relative inline-flex">
      <Icon name={icon} className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2" />
      <select
        className={cn("h-9 cursor-pointer appearance-none rounded-md border border-border bg-background pr-4 pl-10 text-sm font-medium text-foreground shadow-xs hover:bg-muted", className)}
        {...props}
      >
        {children}
      </select>
    </span>
  );
}

/** Time range picker (plus custom dates). Lives in the header, or in the Overview heading. */
export function PeriodControls() {
  const [params, setParams] = useSearchParams();
  const range = params.get("range") ?? DEFAULT_RANGE;
  const set = (k: string, v: string, empty = "") =>
    setParams((prev) => {
      const next = new URLSearchParams(prev);
      if (v && v !== empty) next.set(k, v);
      else next.delete(k);
      next.delete("cursor");
      return next;
    });
  return (
    <>
      <label htmlFor="period-select" className="sr-only">Time range</label>
      <OutlineSelect icon="calendar" id="period-select" value={range} onChange={(e) => set("range", e.target.value, DEFAULT_RANGE)}>
        {RANGES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
      </OutlineSelect>
      {range === "custom" && (
        <>
          <input type="datetime-local" className="lh-control" aria-label="From" value={params.get("from") ?? ""} onChange={(e) => set("from", e.target.value)} />
          <input type="datetime-local" className="lh-control" aria-label="To" value={params.get("to") ?? ""} onChange={(e) => set("to", e.target.value)} />
        </>
      )}
    </>
  );
}

// Order is part of the product: Overview → Traces → Logs → Projects.
const nav = [
  { to: "/", label: "Overview", icon: "layout-dashboard", end: true },
  { to: "/traces", label: "Traces", icon: "git-branch" },
  { to: "/logs", label: "Logs", icon: "list" },
  { to: "/projects", label: "Projects", icon: "folder" },
] as const;

const link = "flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-sidebar-foreground transition-colors hover:bg-sidebar-accent aria-[current=page]:bg-sidebar-accent";

export function Shell() {
  const [params, setParams] = useSearchParams();
  const { pathname } = useLocation();
  const project = params.get("project") ?? "";
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
      // A selected log or trace belongs to the project it was opened in.
      if (k === "project") ["log", "logp", "trace", "trace_project"].forEach((x) => next.delete(x));
      return next;
    });

  const navigate = useNavigate();
  const onLogs = pathname === "/logs";
  const pendingFocus = useRef(false);
  // The Logs QueryBar owns the ⌘K listener; we replay the shortcut to it.
  const pressK = () => window.dispatchEvent(new KeyboardEvent("keydown", { key: "k", metaKey: true }));
  const openSearch = () => {
    if (onLogs) return pressK();
    pendingFocus.current = true;
    navigate(to("/logs"));
  };
  useEffect(() => {
    if (onLogs && pendingFocus.current) {
      pendingFocus.current = false;
      pressK();
    }
  }, [onLogs]);
  useEffect(() => {
    if (onLogs) return;
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        openSearch();
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  });

  const page = nav.find((n) => ("end" in n ? pathname === n.to : pathname.startsWith(n.to)))?.label ?? "";
  const showPeriod = !/^\/(projects|traces\/|logs)/.test(pathname);

  if (projects.data?.length === 0) return <Navigate to="/welcome" replace />;

  return (
    <div className="flex h-dvh overflow-hidden bg-background">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-body"
      >
        Skip to content
      </a>

      <aside className="hidden h-dvh w-sidebar shrink-0 flex-col gap-[18px] border-r border-sidebar-border bg-sidebar px-3 py-[18px] md:flex">
        <div className="flex h-[42px] items-center gap-2.5 px-1.5 py-1">
          <span className="flex size-8 items-center justify-center rounded-[7px] bg-[#1D4ED8] text-base font-bold text-white">L</span>
          <span className="flex flex-col gap-0.5">
            <span className="text-sm leading-none font-bold">LogHub</span>
            <span className="text-[11px] leading-none text-muted-foreground">Acme workspace</span>
          </span>
        </div>
        <span className="text-[10px] font-semibold text-muted-foreground">WORKSPACE</span>
        <nav className="flex flex-col gap-1" aria-label="Sections">
          {nav.map((n) => (
            <NavLink key={n.to} to={to(n.to)} end={"end" in n} className={link}>
              <Icon name={n.icon} /> {n.label}
            </NavLink>
          ))}
        </nav>
        <button type="button" onClick={clearKey} title="Forget master key" className="mt-auto flex h-[38px] items-center gap-2.5 rounded-md px-1.5 text-left hover:bg-sidebar-accent">
          <span className="flex size-[30px] items-center justify-center rounded-full bg-muted text-sm font-semibold">PJ</span>
          <span className="text-xs font-medium text-muted-foreground">Acme workspace</span>
        </button>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex flex-wrap items-center gap-2 border-b border-border bg-background px-4 py-2 md:h-16 md:flex-nowrap md:gap-3 md:px-[30px] md:py-0">
          <span className="mr-auto flex items-center gap-2 md:hidden">
            <span className="flex size-6 items-center justify-center rounded bg-primary text-primary-foreground">
              <Icon name="logs" className="size-3.5" />
            </span>
            <span className="text-body font-semibold tracking-tight">LogHub</span>
          </span>
          <span className="mr-auto hidden text-[13px] text-muted-foreground md:block">Workspace <span aria-hidden className="px-1.5">/</span> {page}</span>
          <button
            type="button"
            onClick={openSearch}
            aria-keyshortcuts="Control+K Meta+K"
            className="hidden items-center gap-1.5 rounded-md px-1 text-xs text-muted-foreground hover:text-foreground md:flex"
          >
            <kbd className="rounded bg-muted px-1.5 py-0.5 font-sans text-[11px] font-medium">⌘ K</kbd>
            Search
          </button>
          <label htmlFor="project-select" className="sr-only">Project</label>
          <OutlineSelect icon="chevrons-up-down" id="project-select" value={project} onChange={(e) => setParam("project", e.target.value)}>
            <option value="">All projects</option>
            {projects.data?.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </OutlineSelect>
          {showPeriod && pathname !== "/" && <PeriodControls />}
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
