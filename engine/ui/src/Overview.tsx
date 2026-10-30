import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router";
import { Histogram } from "@/components/Histogram";
import { MetricCard } from "@/components/MetricCard";
import { Severity } from "@/components/Severity";
import { StateView } from "@/components/StateView";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { bucketSeconds, rangeLabel, resolveRange } from "@/lib/range";
import { scopeBase } from "@/lib/scope";
import { usePageScope } from "@/lib/usePageScope";
import { api } from "./api";
import { formatDuration } from "./traceTree";

type Bucket = { t: string; total: number; errors: number };
type Log = { id: string; project_id: string; timestamp: string; severity: { number: number }; message: string; resource: Record<string, unknown> };
type Trace = { duration_ns: number; error_count: number };

const nf = new Intl.NumberFormat(undefined, { notation: "compact", maximumFractionDigits: 2 });
const percentile = (sorted: number[], p: number) => sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))];

export function Overview() {
  const s = usePageScope();
  const base = scopeBase(s.project);
  const key = [s.project, s.range, s.from, s.to];
  const bounds = () => resolveRange(s);

  const histogram = useQuery({
    queryKey: ["overview", "histogram", ...key],
    queryFn: () => {
      const range = bounds();
      // Custom ranges can be open-ended or huge: pick an interval that keeps buckets under the engine cap.
      const span = range.from && range.to ? (Date.parse(range.to) - Date.parse(range.from)) / 1000 : 0;
      const interval = s.range === "custom" ? Math.max(1, Math.ceil(span / 200)) : bucketSeconds(s.range);
      return api<{ buckets: Bucket[] }>(`${base}/logs/histogram`, { method: "POST", body: { range, interval } });
    },
    retry: false,
  });
  const recent = useQuery({
    queryKey: ["overview", "recent", ...key],
    queryFn: () =>
      api<{ records: Log[] }>(`${base}/logs/search`, { method: "POST", body: { range: bounds(), sort: { field: "timestamp", direction: "desc" }, limit: 5 } }),
    retry: false,
  });
  const traces = useQuery({
    queryKey: ["overview", "traces", ...key],
    queryFn: () => api<{ traces: Trace[]; has_more: boolean }>(`${base}/traces/search`, { method: "POST", body: { range: bounds(), limit: 200 } }),
    retry: false,
  });
  const services = useQuery({
    queryKey: ["overview", "services", ...key],
    queryFn: () => {
      const { from, to } = bounds();
      const qs = new URLSearchParams({ ...(from && { from }), ...(to && { to }) });
      return api<{ values: string[] }>(`${base}/logs/facets/service.name?${qs}`);
    },
    retry: false,
  });

  const buckets = histogram.data?.buckets ?? [];
  const total = buckets.reduce((n, b) => n + b.total, 0);
  const errors = buckets.reduce((n, b) => n + b.errors, 0);
  const durations = (traces.data?.traces ?? []).map((t) => t.duration_ns).sort((a, b) => a - b);
  const sampled = traces.data?.has_more ? "latest 200 traces" : `${durations.length} traces`;
  const retryAll = () => [histogram, recent, traces, services].forEach((q) => q.isError && q.refetch());
  const q = (extra = "") => `${s.search}${s.search ? "&" : "?"}${extra}`.replace(/[?&]$/, "");

  return (
    <div className="flex flex-col gap-4 p-4 sm:p-6">
      <div>
        <h1 className="text-lg font-semibold tracking-tight">Overview</h1>
        <p className="text-body text-muted-foreground">
          {rangeLabel(s.range)} · {s.project ? "one project" : "all projects"}
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <MetricCard label="Total events" value={histogram.isError ? "—" : nf.format(total)} loading={histogram.isPending} hint={histogram.isError ? "Unavailable" : undefined} />
        <MetricCard
          label="Error rate"
          value={histogram.isError ? "—" : total ? `${((errors / total) * 100).toFixed(2)}%` : "0%"}
          loading={histogram.isPending}
          hint={histogram.isError ? "Unavailable" : `${nf.format(errors)} errors or fatal`}
        />
        <MetricCard label="Traces" value={traces.isError ? "—" : nf.format(durations.length)} loading={traces.isPending} hint={traces.isError ? "Unavailable" : traces.data?.has_more ? "200+ in range" : "in range"} />
        <MetricCard
          label="p95 duration"
          value={durations.length ? formatDuration(percentile(durations, 0.95)) : "—"}
          loading={traces.isPending}
          hint={`from ${sampled}`}
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="gap-3 p-4 lg:col-span-2">
          <div className="flex items-baseline justify-between">
            <h2 className="text-md font-medium">Event volume</h2>
            <span className="flex items-center gap-3 text-micro text-muted-foreground">
              <span className="flex items-center gap-1"><span className="size-2 bg-brand-tint ring-1 ring-brand-line" />All</span>
              <span className="flex items-center gap-1"><span className="size-2 bg-error-dot" />Errors</span>
            </span>
          </div>
          {histogram.isPending ? (
            <Skeleton className="h-32 w-full" />
          ) : histogram.isError ? (
            <StateView kind="error" className="py-6" action={{ label: "Try again", onClick: () => histogram.refetch() }}>
              {histogram.error.message}
            </StateView>
          ) : total === 0 ? (
            <StateView kind="empty" className="py-6" title="No events in this period">
              Widen the time range, or check that your application is sending logs.
            </StateView>
          ) : (
            <Histogram buckets={buckets} label={`Event volume, ${nf.format(total)} events, ${nf.format(errors)} errors`} />
          )}
        </Card>

        <Card className="gap-3 p-4">
          <h2 className="text-md font-medium">Services</h2>
          {services.isPending ? (
            <Skeleton className="h-24 w-full" />
          ) : services.isError ? (
            <StateView kind="error" className="py-4" action={{ label: "Try again", onClick: () => services.refetch() }} />
          ) : services.data.values.length === 0 ? (
            <p className="text-body text-muted-foreground">No service reported logs in this period.</p>
          ) : (
            <ul className="flex flex-col">
              {services.data.values.slice(0, 8).map((name) => (
                <li key={name}>
                  <Link className="block truncate rounded px-1 py-1.5 font-mono text-body text-brand hover:bg-muted" to={`/logs${q(`service=${encodeURIComponent(name)}`)}`}>
                    {name}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card className="gap-0 p-0">
        <div className="flex items-center justify-between px-4 py-3">
          <h2 className="text-md font-medium">Recent events</h2>
          <Link className="text-body text-brand hover:underline" to={`/logs${s.search}`}>View all logs</Link>
        </div>
        {recent.isPending ? (
          <Skeleton className="m-4 mt-0 h-32" />
        ) : recent.isError ? (
          <StateView kind="error" action={{ label: "Try again", onClick: () => recent.refetch() }}>{recent.error.message}</StateView>
        ) : recent.data.records.length === 0 ? (
          <StateView kind="empty" title="No logs found">Nothing was logged in this period.</StateView>
        ) : (
          <ul className="divide-y divide-border border-t border-border">
            {recent.data.records.map((r) => (
              <li key={r.id}>
                <Link to={`/logs${q(`log=${r.id}${s.project ? "" : `&logp=${r.project_id}`}`)}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 hover:bg-muted">
                  <time className="tnum text-label text-muted-foreground" dateTime={r.timestamp}>{new Date(r.timestamp).toLocaleTimeString([], { hour12: false })}</time>
                  <Severity n={r.severity.number} />
                  <span className="min-w-0 flex-1 basis-60 truncate text-body">{r.message}</span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {[histogram, recent, traces, services].filter((x) => x.isError).length > 1 && (
        <button type="button" className="self-start text-body text-brand underline" onClick={retryAll}>Retry everything that failed</button>
      )}
    </div>
  );
}
