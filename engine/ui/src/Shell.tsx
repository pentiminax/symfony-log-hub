import { useQuery } from "@tanstack/react-query";
import { NavLink, Outlet, useSearchParams } from "react-router";
import { type Project, api, clearKey } from "./api";
import { Icon } from "./icons";

const nav = [
  { to: "/logs", label: "Logs", icon: "logs" },
  { to: "/traces", label: "Traces", icon: "traces" },
  { to: "/projects", label: "Projects", icon: "projects" },
] as const;

export function Shell() {
  const [params, setParams] = useSearchParams();
  const project = params.get("project") ?? "";
  const projects = useQuery({ queryKey: ["projects"], queryFn: () => api<Project[]>("/v1/projects") });
  // Keep ?project= when moving between sections so later pages can read it.
  const search = project ? `?project=${encodeURIComponent(project)}` : "";

  const links = nav.map((n) => (
    <NavLink key={n.to} to={{ pathname: n.to, search }} className="lh-rail-link">
      <Icon name={n.icon} /> {n.label}
    </NavLink>
  ));

  const logout = (
    <button type="button" onClick={clearKey} className="lh-rail-link shrink-0 p-1.5" title="Forget key">
      <Icon name="sign-out" />
      <span className="sr-only">Forget key</span>
    </button>
  );

  return (
    <div className="flex h-dvh overflow-hidden">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-control focus:bg-surface focus:px-3 focus:py-2 focus:text-body"
      >
        Skip to content
      </a>

      <aside className="sticky top-0 hidden h-dvh w-rail shrink-0 flex-col bg-rail md:flex">
        <div className="flex items-center gap-2.5 px-4 py-4">
          <span className="flex size-7 items-center justify-center rounded-md bg-accent text-accent-ink">
            <Icon name="logs" />
          </span>
          <span className="text-md font-semibold tracking-tight text-rail-ink-strong">LogHub</span>
        </div>
        <nav className="flex flex-1 flex-col gap-0.5 px-2" aria-label="Sections">
          {links}
        </nav>
        <div className="border-t border-rail-line p-2">
          <div className="flex items-center gap-2.5 px-1.5 py-1.5">
            <span className="min-w-0 flex-1 truncate text-micro text-rail-ink">Master key</span>
            {logout}
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex items-center gap-2 bg-rail px-3 py-2 md:hidden">
          <span className="flex size-6 items-center justify-center rounded bg-accent text-accent-ink">
            <Icon name="logs" className="size-3.5" />
          </span>
          <span className="flex-1 text-body font-semibold tracking-tight text-rail-ink-strong">LogHub</span>
          {logout}
        </div>
        <nav className="flex gap-1 border-b border-line bg-surface px-2 py-1 md:hidden" aria-label="Sections (mobile)">
          {nav.map((n) => (
            <NavLink key={n.to} to={{ pathname: n.to, search }} className="lh-tab !border-b-0 aria-[current=page]:font-medium aria-[current=page]:text-ink">
              {n.label}
            </NavLink>
          ))}
        </nav>

        <header className="flex items-center gap-3 border-b border-line bg-surface px-4 py-2">
          <label htmlFor="project-select" className="text-label font-medium text-ink-muted">
            Project
          </label>
          <select
            id="project-select"
            className="lh-control min-w-48"
            value={project}
            onChange={(e) => {
              const next = new URLSearchParams(params);
              if (e.target.value) next.set("project", e.target.value);
              else next.delete("project");
              setParams(next);
            }}
          >
            <option value="">All projects</option>
            {projects.data?.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </header>

        <main id="main" className="min-h-0 min-w-0 flex-1 overflow-y-auto">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
