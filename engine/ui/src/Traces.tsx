import { useQuery } from "@tanstack/react-query";
import { Link, useSearchParams } from "react-router";
import { StateView } from "@/components/StateView";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { MetricCard } from "@/components/MetricCard";
import { Skeleton } from "@/components/ui/skeleton";
import { TraceSheet } from "@/components/TraceSheet";
import { resolveRange } from "@/lib/range";
import { scopeBase } from "@/lib/scope";
import { usePageScope } from "@/lib/usePageScope";
import { type Project, api } from "./api";
import { formatDuration } from "./traceTree";

type Cursor = { start_time: string; trace_id: string; project_id?: string };
type Summary = {
  project_id: string;
  trace_id: string;
  root: { span_id: string; name: string; service_name: string } | null;
  start_time: string;
  duration_ns: number;
  span_count: number;
  error_count: number;
  services: string[];
};
type Result = { traces: Summary[]; next_cursor: Cursor | null; has_more: boolean };

const PER_PAGE = 50;

// "cursor" in the URL is "<start_time>|<trace_id>|<project_id>".
const parseCursor = (raw: string): Cursor | null => {
  const [start_time, trace_id, project_id] = raw.split("|");
  return start_time && trace_id ? { start_time, trace_id, ...(project_id && { project_id }) } : null;
};

const when = (iso: string) => new Date(iso).toLocaleTimeString([], { hour12: false });
const percentile = (sorted: number[], p: number) => sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))];
const MIN_DURATIONS = [["100", "≥ 100 ms"], ["500", "≥ 500 ms"], ["1000", "≥ 1 s"]];
const ROW = "flex h-[42px] items-center gap-3 px-[18px]";

export function Traces() {
  const [params, setParams] = useSearchParams();
  const scope = usePageScope();
  const { project } = scope;
  const service = params.get("service")?.trim() ?? "";
  const q = params.get("q")?.trim() ?? "";
  const errors = params.get("errors") === "1";
  const min = params.get("min") ?? "";
  const cursorRaw = params.get("cursor") ?? "";
  const cursor = parseCursor(cursorRaw);
  const names = useQuery({ queryKey: ["projects"], queryFn: () => api<Project[]>("/v1/projects") }).data;
  const nameOf = (id: string) => names?.find((p) => p.id === id)?.name ?? id.slice(0, 8);

  const edit = (fn: (p: URLSearchParams) => void) =>
    setParams((prev) => {
      const next = new URLSearchParams(prev);
      fn(next);
      next.delete("cursor"); // any filter change goes back to page one
      return next;
    });

  const result = useQuery({
    enabled: scope.ready,
    queryKey: ["traces", project, scope.range, scope.from, scope.to, q, service, errors, min, cursorRaw],
    queryFn: () => {
      const filters = [
        ...(q ? [{ field: "name", operator: "contains", value: q }] : []),
        ...(min ? [{ field: "duration", operator: "greater_than_or_equal", value: Number(min) * 1e6 }] : []),
        ...(service ? [{ field: "service.name", operator: "equals", value: service }] : []),
        ...(errors ? [{ field: "status", operator: "equals", value: "error" }] : []),
      ];
      return api<Result>(`${scopeBase(project)}/traces/search`, {
        method: "POST",
        body: { range: resolveRange(scope), filters, limit: PER_PAGE, cursor },
      });
    },
    placeholderData: (prev) => prev,
    retry: false,
  });

  const withCursor = (extra: Record<string, string>) => {
    const p = new URLSearchParams(params);
    p.delete("cursor");
    Object.entries(extra).forEach(([k, v]) => p.set(k, v));
    return `?${p}`;
  };
  // Selection lives in the URL. In all-projects mode the row's project goes in "trace_project" so the list scope stays put.
  const selected = params.get("trace") ?? "";
  const selectedProject = project || params.get("trace_project") || "";
  const select = (t: Summary) => {
    const p = new URLSearchParams(params);
    p.set("trace", t.trace_id);
    if (project) p.delete("trace_project");
    else p.set("trace_project", t.project_id);
    return `?${p}`;
  };
  const isSelected = (t: Summary) => t.trace_id === selected && t.project_id === selectedProject;
  const closeSheet = () =>
    setParams((prev) => {
      const next = new URLSearchParams(prev);
      next.delete("trace");
      next.delete("trace_project");
      return next;
    });

  const data = result.data;
  const next = data?.next_cursor;
  const filtered = !!(q || service || errors || min);
  const durations = (data?.traces ?? []).map((t) => t.duration_ns).sort((a, b) => a - b);
  const failed = (data?.traces ?? []).filter((t) => t.error_count > 0).length;

  return (
    <div className="flex min-h-full flex-col">
      <header className="flex flex-col gap-[18px] px-4 pt-4 pb-3 sm:px-6">
        <div>
          <h1 className="text-lg font-semibold tracking-tight">Traces</h1>
          <p className="text-body text-muted-foreground">Follow requests across your distributed system.</p>
        </div>
        <form
          key={`${q}|${service}|${errors}|${min}`}
          className="flex flex-wrap items-center gap-2"
          onChange={(e) => e.target instanceof HTMLSelectElement && e.currentTarget.requestSubmit()}
          onSubmit={(e) => {
            e.preventDefault();
            const f = new FormData(e.currentTarget);
            edit((p) => {
              for (const k of ["q", "service", "min"]) {
                const v = String(f.get(k) ?? "").trim();
                if (v) p.set(k, v);
                else p.delete(k);
              }
              if (f.get("status") === "error") p.set("errors", "1");
              else p.delete("errors");
            });
          }}
        >
          <label className="sr-only" htmlFor="lh-q">Search trace or operation</label>
          <input id="lh-q" type="search" name="q" defaultValue={q} placeholder="Search trace or operation" className="lh-control min-w-0 flex-1 basis-48 sm:flex-none" />
          <label className="sr-only" htmlFor="lh-service">Service</label>
          <input id="lh-service" type="search" name="service" defaultValue={service} placeholder="All services" className="lh-control min-w-0 basis-32" />
          <label className="sr-only" htmlFor="lh-status">Status</label>
          <select id="lh-status" name="status" defaultValue={errors ? "error" : ""} className="lh-control">
            <option value="">All status</option>
            <option value="error">Error</option>
          </select>
          <label className="sr-only" htmlFor="lh-min">Min duration</label>
          <select id="lh-min" name="min" defaultValue={min} className="lh-control">
            <option value="">Min duration</option>
            {MIN_DURATIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </form>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <MetricCard label="Traces" value={data ? `${data.traces.length}${data.has_more ? "+" : ""}` : "—"} loading={!data && !result.isError} hint="on this page" />
          <MetricCard label="Failure rate" value={data?.traces.length ? `${((failed / data.traces.length) * 100).toFixed(2)}%` : "—"} loading={!data && !result.isError} hint={`${failed} with errors`} />
          <MetricCard label="P95 duration" value={durations.length ? formatDuration(percentile(durations, 0.95)) : "—"} loading={!data && !result.isError} hint="on this page" />
        </div>
      </header>

      {!scope.ready ? (
        <StateView kind="empty" icon="search" title="Choose a start and an end date">
          The custom range needs both dates before LogHub can list traces.
        </StateView>
      ) : result.isError ? (
        <StateView kind="error" action={{ label: "Try again", onClick: () => result.refetch() }}>{result.error.message}</StateView>
      ) : !data ? (
        <div className="flex flex-col gap-2 px-4 sm:px-6" role="status" aria-busy="true">
          <span className="sr-only">Loading traces</span>
          {Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-10 w-full" />)}
        </div>
      ) : data.traces.length === 0 ? (
        filtered ? (
          <StateView kind="empty" icon="search" title="No traces found" action={{ label: "Clear filters", onClick: () => edit((p) => { p.delete("service"); p.delete("errors"); }) }}>
            Try a wider time range or remove a filter.
          </StateView>
        ) : (
          <StateView kind="empty" title="No traces found">
            {cursor ? "No traces on this page." : <>Send spans to <code className="font-mono">POST /v1/traces</code> with a project API key, or widen the time range.</>}
          </StateView>
        )
      ) : (
        <>
          <Card className="mx-4 gap-0 p-0 sm:mx-6">
            <div className="flex h-12 items-center justify-between px-[18px]">
              <h2 className="text-sm font-semibold">Recent traces</h2>
              <span className="text-xs text-muted-foreground">Most recent first</span>
            </div>
            <div className="flex h-10 items-center gap-3 border-y border-border px-[18px] text-micro font-medium text-muted-foreground" aria-hidden="true">
              <span className="w-[70px] shrink-0 sm:w-[110px]">TIME</span>
              <span className="w-12 shrink-0 sm:w-[90px]">STATUS</span>
              <span className="hidden w-[170px] shrink-0 md:block">SERVICE</span>
              <span className="min-w-0 flex-1">OPERATION</span>
              <span className="w-20 shrink-0 text-right">DURATION</span>
            </div>
            <ul className="text-label" aria-label="Traces, most recent first">
              {data.traces.map((t) => (
                <li key={`${t.project_id}/${t.trace_id}`} className={`border-b border-border last:border-b-0 ${isSelected(t) ? "bg-muted" : "hover:bg-muted"}`}>
                  <Link to={select(t)} aria-current={isSelected(t) ? "true" : undefined} className={ROW}>
                    <time className="tnum w-[70px] shrink-0 text-muted-foreground sm:w-[110px]" dateTime={t.start_time}>{when(t.start_time)}</time>
                    <span className={`w-12 shrink-0 uppercase sm:w-[90px] ${t.error_count > 0 ? "text-error-ink" : ""}`}>{t.error_count > 0 ? "error" : "ok"}</span>
                    <span className="hidden w-[170px] shrink-0 truncate md:block">{!project && `${nameOf(t.project_id)} · `}{t.services.join(", ")}</span>
                    <span className="min-w-0 flex-1 truncate">{t.root?.name ?? "(root span missing)"}</span>
                    <span className="tnum w-20 shrink-0 text-right">{formatDuration(t.duration_ns)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </Card>

          <nav className="flex items-center gap-3 px-4 py-3 sm:px-6" aria-label="Pagination">
            {cursor && (
              <Button variant="outline" render={<Link to={withCursor({})} />}>First page</Button>
            )}
            {data.has_more && next && (
              <Button variant="outline" render={<Link rel="next" to={withCursor({ cursor: `${next.start_time}|${next.trace_id}|${next.project_id ?? ""}` })} />}>Older traces</Button>
            )}
          </nav>

          {selected && <TraceSheet project={selectedProject} traceId={selected} onClose={closeSheet} />}
        </>
      )}
    </div>
  );
}
