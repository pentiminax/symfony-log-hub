import { useQuery } from "@tanstack/react-query";
import { type ReactNode, useState } from "react";
import { StateView } from "@/components/StateView";
import { ApiError, api } from "@/api";
import { type Json, type Span, buildTree, formatDuration, geometry, isError, serviceHue, toUs } from "@/traceTree";

const val = (v: Json) => (v !== null && typeof v === "object" ? JSON.stringify(v) : String(v));

/** GET /v1/projects/{project}/traces/{trace}; one cache entry per trace, shared by panel and route. */
export function useTrace(project: string, traceId: string) {
  return useQuery({
    enabled: !!project && !!traceId,
    queryKey: ["trace", project, traceId],
    queryFn: () => api<{ trace_id: string; spans: Span[] }>(`/v1/projects/${encodeURIComponent(project)}/traces/${encodeURIComponent(traceId)}`),
    retry: false,
  });
}

/** Loading, error-with-retry and not-found states; renders `children` once spans are there. */
export function TraceLoader({ project, traceId, children }: { project: string; traceId: string; children: (spans: Span[]) => ReactNode }) {
  const q = useTrace(project, traceId);
  if (!project) return <StateView kind="empty" title="Choose a project">Choose a project in the selector above to open a trace.</StateView>;
  if (q.isError) {
    const missing = q.error instanceof ApiError && (q.error.status === 404 || q.error.status === 400);
    return missing ? (
      <StateView kind="empty" title="Trace not found">Trace not found in this project.</StateView>
    ) : (
      <StateView kind="error" title="Unable to load trace" action={{ label: "Try again", onClick: () => q.refetch() }}>{q.error.message}</StateView>
    );
  }
  if (!q.data) return <StateView kind="loading" title="Loading trace" />;
  if (!q.data.spans.length) return <StateView kind="empty" title="Trace not found">Trace not found in this project.</StateView>;
  return <>{children(q.data.spans)}</>;
}

/** Name, span count, error count and total duration, derived from the spans alone. */
export function traceStats(spans: Span[]) {
  const rows = buildTree(spans);
  const { total, bar } = geometry(spans);
  return { rows, bar, totalNs: total * 1000, root: rows[0].span, errors: spans.filter(isError).length };
}

function Pairs({ title, data }: { title: string; data: Record<string, Json> }) {
  const entries = Object.entries(data);
  if (!entries.length) return null;
  return (
    <>
      <h4 className="mt-3 mb-1 text-label font-medium text-ink-muted">{title}</h4>
      <dl className="grid grid-cols-[minmax(8rem,16rem)_1fr] gap-x-4 gap-y-0.5 font-mono text-micro">
        {entries.map(([k, v]) => (
          <div key={k} className="contents">
            <dt className="truncate text-ink-muted" title={k}>{k}</dt>
            <dd className="break-all">{val(v)}</dd>
          </div>
        ))}
      </dl>
    </>
  );
}

export function SpanDetails({ span }: { span: Span }) {
  return (
    <>
      <Pairs
        title="Span"
        data={{
          span_id: span.span_id,
          ...(span.parent_span_id ? { parent_span_id: span.parent_span_id } : {}),
          kind: span.kind,
          status: span.status.code + (span.status.message ? `: ${span.status.message}` : ""),
          "service.name": span.service_name,
          duration: formatDuration(span.duration_ns),
          start: span.start_time,
          ...(span.scope.name ? { scope: span.scope.name + (span.scope.version ? ` ${span.scope.version}` : "") } : {}),
        }}
      />
      <Pairs title="Attributes" data={span.attributes} />
      <Pairs title="Resource" data={span.resource} />
      {span.events.map((e, i) => (
        <div key={i}>
          <h4 className="mt-3 mb-1 text-label font-medium text-ink-muted">
            Event: <span className="font-mono">{e.name}</span>
            {e.timestamp && <span className="tnum font-normal"> ({e.timestamp})</span>}
          </h4>
          {Object.entries(e.attributes).map(([k, v]) =>
            k === "exception.stacktrace" ? (
              <pre key={k} className="max-h-96 overflow-auto rounded-control bg-sunken p-3 font-mono text-micro leading-relaxed text-ink-muted">{val(v)}</pre>
            ) : (
              <Pairs key={k} title="" data={{ [k]: v }} />
            ),
          )}
        </div>
      ))}
    </>
  );
}

// Body renders only while open (a trace can hold 10000 spans); state is per row so a toggle re-renders one row.
function SpanRow({ span, depth, bar, offsetNs }: { span: Span; depth: number; bar: { left: number; width: number }; offsetNs: number }) {
  const [open, setOpen] = useState(false);
  const hue = serviceHue(span.service_name);
  const err = isError(span);
  const label = `${span.service_name} · ${span.name}`;
  return (
    <li className="border-b border-border">
      <details onToggle={(e) => setOpen(e.currentTarget.open)}>
        <summary
          className={`grid cursor-pointer grid-cols-[200px_1fr] items-center py-2 hover:bg-sunken ${err ? "bg-error-tint" : ""}`}
        >
          <span className="flex min-w-0 flex-col gap-1 pr-2" style={{ paddingLeft: `${depth * 8}px` }} title={label}>
            <span className="truncate text-label font-medium">{span.service_name}</span>
            <span className="tnum truncate text-[10px] text-ink-muted">
              {formatDuration(span.duration_ns)} &middot; {span.name}
              {err && <span className="ml-1.5 font-medium text-error-ink">error</span>}
            </span>
          </span>
          <span
            className="relative block h-2.5"
            role="img"
            aria-label={`${label}: ${formatDuration(span.duration_ns)}, starts ${formatDuration(Math.max(0, offsetNs))} after the first span${err ? ", failed" : ""}`}
          >
            <span
              className="absolute inset-y-0 rounded-[3px]"
              style={{ left: `${bar.left.toFixed(3)}%`, width: `${bar.width.toFixed(3)}%`, backgroundColor: err ? "var(--color-error-ink)" : `hsl(${hue} 60% 72%)` }}
            />
          </span>
        </summary>
        {open && (
          <div className="rounded-sm bg-sunken px-3 py-3" style={{ marginLeft: `${depth * 12}px` }}>
            <SpanDetails span={span} />
          </div>
        )}
      </details>
    </li>
  );
}

/** Time axis plus one row per span; positions come from the real timestamps (geometry()). */
export function TraceWaterfall({ spans }: { spans: Span[] }) {
  const { rows, bar, totalNs } = traceStats(spans);
  const t0 = toUs(spans[0].start_time);
  return (
    <div className="flex flex-col gap-3">
      <div className="tnum flex justify-between pl-[200px] text-[10px] text-ink-muted" aria-hidden="true">
        {[0, 0.25, 0.5, 0.75, 1].map((f) => <span key={f}>{f === 0 ? "0 ms" : formatDuration(totalNs * f)}</span>)}
      </div>
      <ol className="flex flex-col" aria-label="Spans of this trace">
        {rows.map(({ span, depth }) => (
          <SpanRow key={span.span_id} span={span} depth={depth} bar={bar(span)} offsetNs={(toUs(span.start_time) - t0) * 1000} />
        ))}
      </ol>
    </div>
  );
}
