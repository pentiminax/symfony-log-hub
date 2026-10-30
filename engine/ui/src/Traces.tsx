import { useQuery } from "@tanstack/react-query";
import { useEffect, useRef } from "react";
import { Link, useSearchParams } from "react-router";
import { StateView } from "@/components/StateView";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { TraceLoader, TraceWaterfall, traceStats } from "@/components/TraceWaterfall";
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

const when = (iso: string) => {
  const d = new Date(iso);
  return `${d.toLocaleString(undefined, { month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false })}.${String(d.getMilliseconds()).padStart(3, "0")}`;
};

function Errors({ n }: { n: number }) {
  return n > 0 ? (
    <Badge variant="destructive">
      {n} error{n > 1 ? "s" : ""}
    </Badge>
  ) : (
    <span className="text-muted-foreground">&mdash;</span>
  );
}

export function Traces() {
  const [params, setParams] = useSearchParams();
  const scope = usePageScope();
  const { project } = scope;
  const service = params.get("service")?.trim() ?? "";
  const errors = params.get("errors") === "1";
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
    queryKey: ["traces", project, scope.range, scope.from, scope.to, service, errors, cursorRaw],
    queryFn: () => {
      const filters = [
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
  const panel = useRef<HTMLElement>(null);
  const first = useRef(true);
  useEffect(() => {
    if (first.current) first.current = false;
    else panel.current?.scrollIntoView({ block: "nearest" });
  }, [selected]);

  const data = result.data;
  const next = data?.next_cursor;
  const filtered = !!(service || errors);

  return (
    <div className="flex min-h-full flex-col">
      <header className="flex flex-col gap-3 px-4 pt-4 pb-3 sm:px-6">
        <div>
          <h1 className="text-lg font-semibold tracking-tight">Traces</h1>
          <p className="text-body text-muted-foreground">Follow requests across your instrumented services.</p>
        </div>
        <form
          key={`${service}|${errors}`}
          className="flex flex-wrap items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            const f = new FormData(e.currentTarget);
            edit((p) => {
              const s = String(f.get("service") ?? "").trim();
              if (s) p.set("service", s);
              else p.delete("service");
              if (f.get("errors")) p.set("errors", "1");
              else p.delete("errors");
            });
          }}
        >
          <label className="sr-only" htmlFor="lh-service">Service</label>
          <input id="lh-service" type="search" name="service" defaultValue={service} placeholder="Service name" className="lh-control min-w-0 flex-1 basis-40 sm:flex-none" />
          <label className="lh-control gap-1.5">
            <input type="checkbox" name="errors" value="1" defaultChecked={errors} /> Errors only
          </label>
          <Button type="submit" variant="outline">Apply</Button>
        </form>
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
          <Table className="hidden md:table">
            <caption className="sr-only">Traces, most recent first</caption>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Root span</TableHead>
                {!project && <TableHead>Project</TableHead>}
                <TableHead>Services</TableHead>
                <TableHead className="w-48">Start</TableHead>
                <TableHead className="w-28 text-right">Duration</TableHead>
                <TableHead className="w-20 text-right">Spans</TableHead>
                <TableHead className="w-28 pr-6">Errors</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.traces.map((t) => (
                <TableRow key={`${t.project_id}/${t.trace_id}`} data-state={isSelected(t) ? "selected" : undefined}>
                  <TableCell className="pl-6">
                    <Link className="font-mono text-brand underline" to={select(t)} aria-current={isSelected(t) ? "true" : undefined}>{t.root?.name ?? "(root span missing)"}</Link>
                  </TableCell>
                  {!project && <TableCell className="text-muted-foreground">{nameOf(t.project_id)}</TableCell>}
                  <TableCell className="text-muted-foreground">{t.services.join(", ")}</TableCell>
                  <TableCell className="tnum text-muted-foreground"><time dateTime={t.start_time}>{when(t.start_time)}</time></TableCell>
                  <TableCell className="tnum text-right">{formatDuration(t.duration_ns)}</TableCell>
                  <TableCell className="tnum text-right">{t.span_count}</TableCell>
                  <TableCell className="pr-6"><Errors n={t.error_count} /></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          <ul className="divide-y divide-border border-y border-border md:hidden" aria-label="Traces, most recent first">
            {data.traces.map((t) => (
              <li key={`${t.project_id}/${t.trace_id}`} className={isSelected(t) ? "bg-muted" : undefined}>
                <Link to={select(t)} aria-current={isSelected(t) ? "true" : undefined} className="flex flex-col gap-1 px-4 py-3 active:bg-muted">
                  <span className="flex items-start justify-between gap-2">
                    <span className="min-w-0 break-words font-mono text-body font-medium">{t.root?.name ?? "(root span missing)"}</span>
                    <span className="tnum shrink-0 text-body">{formatDuration(t.duration_ns)}</span>
                  </span>
                  <span className="truncate text-label text-muted-foreground">
                    {!project && `${nameOf(t.project_id)} · `}{t.services.join(", ")}
                  </span>
                  <span className="flex items-center gap-2 text-label text-muted-foreground">
                    <time className="tnum" dateTime={t.start_time}>{when(t.start_time)}</time>
                    <span>· {t.span_count} spans</span>
                    {t.error_count > 0 && <Errors n={t.error_count} />}
                  </span>
                </Link>
              </li>
            ))}
          </ul>

          <nav className="flex items-center gap-3 px-4 py-3 sm:px-6" aria-label="Pagination">
            {cursor && (
              <Button variant="outline" render={<Link to={withCursor({})} />}>First page</Button>
            )}
            {data.has_more && next && (
              <Button variant="outline" render={<Link rel="next" to={withCursor({ cursor: `${next.start_time}|${next.trace_id}|${next.project_id ?? ""}` })} />}>Older traces</Button>
            )}
          </nav>

          {selected && (
            <section ref={panel} aria-label="Trace details" className="mx-4 mb-4 flex scroll-mb-4 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:mx-6 md:p-[18px]">
              <TraceLoader project={selectedProject} traceId={selected}>
                {(spans) => {
                  const { root, totalNs, errors } = traceStats(spans);
                  const dur = formatDuration(totalNs);
                  return (
                    <>
                      <div className="flex items-center justify-between gap-3">
                        <h2 className="min-w-0 truncate text-[14px] font-semibold">{root.name}</h2>
                        <Badge variant="secondary" className="tnum hidden shrink-0 md:inline-flex">{dur}</Badge>
                      </div>
                      <p className="tnum text-micro text-muted-foreground">
                        Trace {selected.slice(0, 8)} &middot; {spans.length} spans &middot; <span className="md:hidden">{dur}</span>
                        <time className="hidden md:inline" dateTime={root.start_time}>{new Date(root.start_time).toLocaleString(undefined, { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false })}</time>
                        {errors > 0 && <> &middot; {errors} error{errors > 1 ? "s" : ""}</>}
                      </p>
                      <TraceWaterfall spans={spans} />
                    </>
                  );
                }}
              </TraceLoader>
            </section>
          )}
        </>
      )}
    </div>
  );
}
