import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { Link, useParams, useSearchParams } from "react-router";
import { ApiError, api } from "./api";
import { Message } from "./Traces";
import { type Json, type Span, buildTree, formatDuration, geometry, isError, serviceHue, toUs } from "./traceTree";

const val = (v: Json) => (v !== null && typeof v === "object" ? JSON.stringify(v) : String(v));

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

function SpanDetails({ span }: { span: Span }) {
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
  const { left, width } = bar;
  return (
    <li className="border-b border-line">
      <details className="group" onToggle={(e) => setOpen(e.currentTarget.open)}>
        <summary
          className={`grid cursor-pointer grid-cols-[minmax(10rem,32%)_1fr_5.5rem] items-center gap-3 px-4 py-1.5 hover:bg-sunken sm:px-6 ${err ? "bg-error-tint" : ""}`}
        >
          <span className="flex min-w-0 items-center gap-1.5" style={{ paddingLeft: `${depth * 1.25}rem` }}>
            <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: `hsl(${hue} 55% 45%)` }} aria-hidden="true" />
            <span className="truncate font-mono text-body" title={span.name}>{span.name}</span>
            <span className="hidden shrink-0 text-micro text-ink-faint sm:inline">{span.service_name}</span>
            <span className="hidden shrink-0 text-micro text-ink-faint sm:inline">{span.kind}</span>
            {err && <span className="shrink-0 text-micro font-medium text-error-ink">error</span>}
          </span>
          <span
            className="relative block h-4 rounded-sm bg-sunken"
            role="img"
            aria-label={`${span.name}: ${formatDuration(span.duration_ns)}, starts ${formatDuration(Math.max(0, offsetNs))} after the first span${err ? ", failed" : ""}`}
          >
            <span
              className={`absolute inset-y-0 rounded-sm ${err ? "ring-2 ring-error-ink" : ""}`}
              style={{ left: `${left.toFixed(3)}%`, width: `${width.toFixed(3)}%`, backgroundColor: err ? "var(--color-error-ink)" : `hsl(${hue} 55% 45%)` }}
            />
          </span>
          <span className="tnum text-right text-body">{formatDuration(span.duration_ns)}</span>
        </summary>
        {open && (
          <div className="bg-surface px-4 py-3 sm:px-6" style={{ paddingLeft: `${1.5 + depth * 1.25}rem` }}>
            <SpanDetails span={span} />
          </div>
        )}
      </details>
    </li>
  );
}

export function TraceDetail() {
  const { traceId = "" } = useParams();
  const [params] = useSearchParams();
  const project = params.get("project") ?? "";
  const q = useQuery({
    enabled: !!project,
    queryKey: ["trace", project, traceId],
    queryFn: () => api<{ trace_id: string; spans: Span[] }>(`/v1/projects/${encodeURIComponent(project)}/traces/${encodeURIComponent(traceId)}`),
    retry: false,
  });
  const back = (
    <p className="text-label">
      <Link className="text-accent underline" to={{ pathname: "/traces", search: project ? `?project=${encodeURIComponent(project)}` : "" }}>
        &larr; Traces
      </Link>
    </p>
  );

  if (!project) return <div className="p-6">{back}<Message>Choose a project in the selector above to open a trace.</Message></div>;
  if (q.isError)
    return (
      <div className="px-4 py-4 sm:px-6">
        {back}
        {q.error instanceof ApiError && (q.error.status === 404 || q.error.status === 400) ? (
          <Message>Trace not found in this project.</Message>
        ) : (
          <div className="mt-3"><Message alert>{q.error.message}</Message></div>
        )}
      </div>
    );
  const spans = q.data?.spans;
  if (!spans) return <div className="px-4 py-4 sm:px-6">{back}<Message>Loading…</Message></div>;
  if (!spans.length) return <div className="px-4 py-4 sm:px-6">{back}<Message>Trace not found in this project.</Message></div>;

  const rows = buildTree(spans);
  const { total, bar } = geometry(spans);
  const root = rows[0].span;
  const errorCount = spans.filter(isError).length;
  const logs = new URLSearchParams({ project, range: "7d" });
  logs.append("f", `trace.id|equals|${traceId}`);

  return (
    <div className="flex min-h-full flex-col">
      <header className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 pt-4 pb-3 sm:px-6">
        <div className="mr-auto min-w-0">
          {back}
          <h1 className="truncate text-lg font-semibold tracking-tight">{root.name}</h1>
          <p className="font-mono text-micro text-ink-muted">{traceId}</p>
        </div>
        <p className="tnum text-body text-ink-muted">
          {formatDuration(total * 1000)} &middot; {spans.length} spans &middot; {errorCount} error{errorCount !== 1 ? "s" : ""}
        </p>
        <Link className="lh-button lh-button-quiet" to={`/logs?${logs}`}>
          View logs for this trace
        </Link>
      </header>

      <ol className="border-t border-line" aria-label="Spans of this trace">
        {rows.map(({ span, depth }) => (
          <SpanRow key={span.span_id} span={span} depth={depth} bar={bar(span)} offsetNs={(toUs(span.start_time) - toUs(spans[0].start_time)) * 1000} />
        ))}
      </ol>
    </div>
  );
}
