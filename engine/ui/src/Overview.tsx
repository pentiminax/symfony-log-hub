import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router";
import { Histogram } from "@/components/Histogram";
import { MetricCard } from "@/components/MetricCard";
import { levelOf } from "@/components/Severity";
import { StateView } from "@/components/StateView";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { bucketSeconds, rangeLabel, resolveRange } from "@/lib/range";
import { scopeBase } from "@/lib/scope";
import { usePageScope } from "@/lib/usePageScope";
import { api } from "./api";
import { PeriodControls } from "./Shell";
import { formatDuration } from "./traceTree";

type Bucket = { t: string; total: number; errors: number };
type Log = { id: string; project_id: string; timestamp: string; severity: { number: number }; message: string; resource: Record<string, unknown> };
type Trace = { duration_ns: number; error_count: number };

const ROW = "flex h-[42px] items-center gap-3 px-[18px]";
const LEVEL_INK = { trace: "text-ink", debug: "text-ink", info: "text-info-ink", warn: "text-warn-ink", error: "text-error-ink", fatal: "text-fatal-ink" };
const nf = new Intl.NumberFormat(undefined, { notation: "compact", maximumFractionDigits: 2 });
const formatInterval = (s: number) => (s < 60 ? `${s} seconds` : s < 3600 ? `${s / 60} minutes` : s < 86400 ? `${s / 3600} hours` : `${s / 86400} days`);
const percentile = (sorted: number[], p: number) => sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))];

export function Overview() {
  const s = usePageScope();
  const base = scopeBase(s.project);
  const key = [s.project, s.range, s.from, s.to];
  const enabled = s.ready;
  const bounds = () => resolveRange(s);

  const histogram = useQuery({
    enabled,
    queryKey: ["overview", "histogram", ...key],
    queryFn: () => {
      const range = bounds();
      // Custom ranges can be open-ended or huge: pick an interval that keeps buckets under the engine cap.
      const span = range.from && range.to ? (Date.parse(range.to) - Date.parse(range.from)) / 1000 : 0;
      const interval = s.range === "custom" ? Math.min(86400, Math.max(1, Math.ceil(span / 200))) : bucketSeconds(s.range);
      return api<{ buckets: Bucket[] }>(`${base}/logs/histogram`, { method: "POST", body: { range, interval } });
    },
    retry: false,
  });
  const recent = useQuery({
    enabled,
    queryKey: ["overview", "recent", ...key],
    queryFn: () =>
      api<{ records: Log[] }>(`${base}/logs/search`, { method: "POST", body: { range: bounds(), sort: { field: "timestamp", direction: "desc" }, limit: 5 } }),
    retry: false,
  });
  const traces = useQuery({
    enabled,
    queryKey: ["overview", "traces", ...key],
    queryFn: () => api<{ traces: Trace[]; has_more: boolean }>(`${base}/traces/search`, { method: "POST", body: { range: bounds(), limit: 200 } }),
    retry: false,
  });
  const services = useQuery({
    enabled,
    queryKey: ["overview", "services", ...key],
    queryFn: () => {
      const { from, to } = bounds();
      const qs = new URLSearchParams({ ...(from && { from }), ...(to && { to }) });
      return api<{ services: { name: string; total: number; errors: number }[] }>(`${base}/logs/services?${qs}`);
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

  if (!s.ready)
    return (
      <div className="p-4 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h1 className="text-lg font-semibold tracking-tight">Overview</h1>
          <div className="flex flex-wrap items-center gap-2"><PeriodControls /></div>
        </div>
        <StateView kind="empty" icon="search" title="Choose a start and an end date">
          The custom range needs both dates before LogHub can load the overview.
        </StateView>
      </div>
    );

  return (
    <div className="flex flex-col gap-4 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold tracking-tight">Overview</h1>
          <p className="text-body text-muted-foreground">
            {rangeLabel(s.range)} · {s.project ? "one project" : "all projects"}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2"><PeriodControls /></div>
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

      <div className="grid gap-3 lg:grid-cols-[1fr_290px]">
        <Card className="gap-3 rounded-lg border border-border bg-sidebar ring-0 p-[18px]">
          <div>
            <div className="flex items-baseline justify-between">
              <h2 className="text-md font-semibold">Event volume</h2>
              <span className="text-xs text-[#1D4ED8]">● Events</span>
            </div>
            {buckets.length > 1 && (
              <p className="mt-3 text-xs text-muted-foreground">Events per {formatInterval((Date.parse(buckets[1].t) - Date.parse(buckets[0].t)) / 1000)}</p>
            )}
          </div>
          {histogram.isPending ? (
            <Skeleton className="h-[135px] w-full" />
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

        <Card className="gap-[15px] rounded-lg border border-border ring-0 p-[18px]">
          <div>
            <h2 className="text-sm font-semibold">Service health</h2>
            <p className="text-micro text-muted-foreground">Availability in this period</p>
          </div>
          {services.isPending ? (
            <Skeleton className="h-24 w-full" />
          ) : services.isError ? (
            <StateView kind="error" className="py-4" action={{ label: "Try again", onClick: () => services.refetch() }} />
          ) : services.data.services.length === 0 ? (
            <p className="text-body text-muted-foreground">No service reported logs in this period.</p>
          ) : (
            <ul className="flex flex-col gap-[15px]">
              {services.data.services.slice(0, 8).map(({ name, total, errors }) => {
                const up = (1 - errors / total) * 100;
                const ink = up >= 99 ? "text-[#15805D]" : up >= 95 ? "text-warn-ink" : "text-error-ink";
                return (
                  <li key={name}>
                    <Link className="flex items-center justify-between gap-2 text-label text-foreground hover:underline" to={`/logs${q(`service=${encodeURIComponent(name)}`)}`}>
                      <span className="flex min-w-0 items-center gap-2">
                        <span className={`size-1.5 shrink-0 rounded-full bg-current ${ink}`} aria-hidden="true" />
                        <span className="truncate">{name}</span>
                      </span>
                      <span className={`tnum text-micro font-semibold ${ink}`}>{up.toFixed(2)}%</span>
                    </Link>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>
      </div>

      <Card className="gap-0 p-0">
        <div className="flex h-14 items-center justify-between px-[18px]">
          <h2 className="text-sm font-semibold">Recent events</h2>
          <Link className="text-label font-semibold text-brand hover:underline" to={`/logs${s.search}`}>View all logs →</Link>
        </div>
        {recent.isPending ? (
          <Skeleton className="m-4 mt-0 h-32" />
        ) : recent.isError ? (
          <StateView kind="error" action={{ label: "Try again", onClick: () => recent.refetch() }}>{recent.error.message}</StateView>
        ) : recent.data.records.length === 0 ? (
          <StateView kind="empty" title="No logs found">Nothing was logged in this period.</StateView>
        ) : (
          <div className="text-label">
            <div className={`${ROW} border-b border-border text-micro font-medium text-muted-foreground`}>
              <span className="w-[120px] shrink-0">TIME</span>
              <span className="w-20 shrink-0">LEVEL</span>
              <span className="w-[150px] shrink-0">SERVICE</span>
              <span className="min-w-0 flex-1">MESSAGE</span>
            </div>
            {recent.data.records.map((r) => (
              <Link key={r.id} to={`/logs${q(`log=${r.id}${s.project ? "" : `&logp=${r.project_id}`}`)}`} className={`${ROW} border-b border-border last:border-b-0 hover:bg-muted`}>
                <time className="tnum w-[120px] shrink-0 text-muted-foreground" dateTime={r.timestamp}>
                  {new Date(r.timestamp).toLocaleTimeString([], { hour12: false, fractionalSecondDigits: 3 })}
                </time>
                <span className={`w-20 shrink-0 uppercase ${LEVEL_INK[levelOf(r.severity.number)]}`}>{levelOf(r.severity.number)}</span>
                <span className="w-[150px] shrink-0 truncate">{String(r.resource["service.name"] ?? "")}</span>
                <span className="min-w-0 flex-1 truncate">{r.message}</span>
              </Link>
            ))}
          </div>
        )}
      </Card>
      {[histogram, recent, traces, services].filter((x) => x.isError).length > 1 && (
        <button type="button" className="self-start text-body text-brand underline" onClick={retryAll}>Retry everything that failed</button>
      )}
    </div>
  );
}
