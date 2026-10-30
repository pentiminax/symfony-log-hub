import { useQuery } from "@tanstack/react-query";
import { Link, useSearchParams } from "react-router";
import { api } from "./api";
import { Icon } from "./icons";
import { formatDuration } from "./traceTree";

type Cursor = { start_time: string; trace_id: string };
type Summary = {
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
const RANGES = [
  ["15m", "Last 15 minutes", 15 * 60e3],
  ["1h", "Last 1 hour", 3600e3],
  ["24h", "Last 24 hours", 24 * 3600e3],
  ["7d", "Last 7 days", 7 * 24 * 3600e3],
] as const;

export const Message = ({ children, alert }: { children: React.ReactNode; alert?: boolean }) =>
  alert ? (
    <div role="alert" className="mx-4 mb-3 flex items-start gap-2.5 rounded-panel bg-error-tint px-3 py-2.5 text-body text-error-ink sm:mx-6">
      <Icon name="alert" className="mt-px size-4 shrink-0" />
      <span>{children}</span>
    </div>
  ) : (
    <p className="px-6 py-10 text-center text-body text-ink-muted">{children}</p>
  );

// "cursor" in the URL is "<start_time>|<trace_id>".
const parseCursor = (raw: string): Cursor | null => {
  const [start_time, trace_id] = raw.split("|");
  return start_time && trace_id ? { start_time, trace_id } : null;
};

export function Traces() {
  const [params, setParams] = useSearchParams();
  const project = params.get("project") ?? "";
  const range = RANGES.find((r) => r[0] === params.get("range"))?.[0] ?? "24h";
  const service = params.get("service")?.trim() ?? "";
  const errors = params.get("errors") === "1";
  const cursorRaw = params.get("cursor") ?? "";
  const cursor = parseCursor(cursorRaw);

  const edit = (fn: (p: URLSearchParams) => void) =>
    setParams((prev) => {
      const next = new URLSearchParams(prev);
      fn(next);
      next.delete("cursor"); // any filter change goes back to page one
      return next;
    });

  const result = useQuery({
    enabled: !!project,
    queryKey: ["traces", project, range, service, errors, cursorRaw],
    queryFn: () => {
      const ms = RANGES.find((r) => r[0] === range)![2];
      const now = Date.now();
      const filters = [
        ...(service ? [{ field: "service.name", operator: "equals", value: service }] : []),
        ...(errors ? [{ field: "status", operator: "equals", value: "error" }] : []),
      ];
      return api<Result>(`/v1/projects/${encodeURIComponent(project)}/traces/search`, {
        method: "POST",
        body: { range: { from: new Date(now - ms).toISOString(), to: new Date(now).toISOString() }, filters, limit: PER_PAGE, cursor },
      });
    },
    placeholderData: (prev) => prev,
    retry: false,
  });

  const withProject = (extra: Record<string, string>) => {
    const p = new URLSearchParams({ project });
    if (range !== "24h") p.set("range", range);
    if (service) p.set("service", service);
    if (errors) p.set("errors", "1");
    Object.entries(extra).forEach(([k, v]) => p.set(k, v));
    return `?${p}`;
  };

  const data = result.data;
  const next = data?.next_cursor;

  return (
    <div className="flex min-h-full flex-col">
      <header className="px-4 pt-4 pb-3 sm:px-6">
        <form
          key={`${service}|${errors}`}
          className="flex flex-wrap items-center gap-3"
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
          <h1 className="mr-auto text-lg font-semibold tracking-tight">Traces</h1>
          <label className="sr-only" htmlFor="lh-range">
            Time range
          </label>
          <select
            id="lh-range"
            className="lh-control pr-1.5"
            value={range}
            onChange={(e) => edit((p) => (e.target.value === "24h" ? p.delete("range") : p.set("range", e.target.value)))}
          >
            {RANGES.map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
          </select>
          <label className="sr-only" htmlFor="lh-service">
            Service
          </label>
          <input id="lh-service" type="search" name="service" defaultValue={service} placeholder="Service name" className="lh-control" />
          <label className="lh-control gap-1.5">
            <input type="checkbox" name="errors" value="1" defaultChecked={errors} /> Errors only
          </label>
          <button type="submit" className="lh-button lh-button-quiet">
            Apply
          </button>
        </form>
      </header>

      {!project ? (
        <Message>Traces are stored per project. Choose one in the selector above to explore its traces.</Message>
      ) : result.isError ? (
        <Message alert>{result.error.message}</Message>
      ) : !data ? (
        <Message>Loading…</Message>
      ) : data.traces.length === 0 ? (
        <Message>
          No traces {cursor ? "on this page" : "in this time range"}.
          {!cursor && (
            <>
              {" "}
              Send spans to <code className="font-mono">POST /v1/traces</code> with this project's API key.
            </>
          )}
        </Message>
      ) : (
        <>
          <div className="overflow-x-auto border-t border-line">
            <table className="w-full min-w-3xl border-collapse text-body">
              <caption className="sr-only">Traces, most recent first</caption>
              <thead className="bg-canvas/95">
                <tr className="border-b border-line text-left text-label font-medium text-ink-muted">
                  <th scope="col" className="px-4 py-2 font-medium sm:px-6">Root span</th>
                  <th scope="col" className="px-2 py-2 font-medium">Services</th>
                  <th scope="col" className="w-48 px-2 py-2 font-medium">Start</th>
                  <th scope="col" className="w-28 px-2 py-2 text-right font-medium">Duration</th>
                  <th scope="col" className="w-20 px-2 py-2 text-right font-medium">Spans</th>
                  <th scope="col" className="w-24 px-4 py-2 font-medium sm:px-6">Errors</th>
                </tr>
              </thead>
              <tbody>
                {data.traces.map((t) => {
                  const d = new Date(t.start_time);
                  return (
                    <tr key={t.trace_id} className="border-b border-line hover:bg-sunken">
                      <th scope="row" className="px-4 py-2 text-left font-normal sm:px-6">
                        <Link className="font-mono text-accent underline" to={`/traces/${t.trace_id}?project=${encodeURIComponent(project)}`}>
                          {t.root?.name ?? "(root span missing)"}
                        </Link>
                      </th>
                      <td className="px-2 py-2 text-ink-muted">{t.services.join(", ")}</td>
                      <td className="tnum px-2 py-2 text-ink-muted">
                        <time dateTime={t.start_time}>
                          {d.toLocaleString(undefined, { month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false })}.
                          {String(d.getMilliseconds()).padStart(3, "0")}
                        </time>
                      </td>
                      <td className="tnum px-2 py-2 text-right">{formatDuration(t.duration_ns)}</td>
                      <td className="tnum px-2 py-2 text-right">{t.span_count}</td>
                      <td className="px-4 py-2 sm:px-6">
                        {t.error_count > 0 ? (
                          <span className="lh-severity bg-error-tint text-error-ink">
                            {t.error_count} error{t.error_count > 1 ? "s" : ""}
                          </span>
                        ) : (
                          <span className="text-ink-faint">&mdash;</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <nav className="flex items-center gap-3 px-4 py-3 sm:px-6" aria-label="Pagination">
            {cursor && (
              <Link className="lh-button lh-button-quiet" to={withProject({})}>
                First page
              </Link>
            )}
            {data.has_more && next && (
              <Link className="lh-button lh-button-quiet" rel="next" to={withProject({ cursor: `${next.start_time}|${next.trace_id}` })}>
                Older traces
              </Link>
            )}
          </nav>
        </>
      )}
    </div>
  );
}
