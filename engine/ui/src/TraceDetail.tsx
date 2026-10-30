import { Link, useParams, useSearchParams } from "react-router";
import { TraceLoader, TraceWaterfall, traceStats } from "@/components/TraceWaterfall";
import { formatDuration } from "./traceTree";

export function TraceDetail() {
  const { traceId = "" } = useParams();
  const [params] = useSearchParams();
  // The trace's own project; "project" is the list scope the user came from.
  const project = params.get("trace_project") ?? params.get("project") ?? "";
  const scope = new URLSearchParams([...params].filter(([k]) => ["project", "range", "from", "to"].includes(k))).toString();
  const back = (
    <p className="text-label">
      <Link className="text-brand underline" to={{ pathname: "/traces", search: scope ? `?${scope}` : "" }}>
        &larr; Traces
      </Link>
    </p>
  );
  const logs = new URLSearchParams({ project, range: "7d" });
  logs.append("f", `trace.id|equals|${traceId}`);

  return (
    <div className="flex min-h-full flex-col">
    <div className="px-4 pt-4 sm:px-6">{back}</div>
    <TraceLoader project={project} traceId={traceId}>
      {(spans) => {
        const { root, totalNs, errors } = traceStats(spans);
        return (
          <>
            <header className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 pt-2 pb-3 sm:px-6">
              <div className="mr-auto min-w-0">
                <h1 className="truncate text-lg font-semibold tracking-tight">{root.name}</h1>
                <p className="font-mono text-micro text-ink-muted">{traceId}</p>
              </div>
              <p className="tnum text-body text-ink-muted">
                {formatDuration(totalNs)} &middot; {spans.length} spans &middot; {errors} error{errors !== 1 ? "s" : ""}
              </p>
              <Link className="lh-button lh-button-quiet" to={`/logs?${logs}`}>
                View logs for this trace
              </Link>
            </header>
            <div className="px-4 pb-4 sm:px-6">
              <TraceWaterfall spans={spans} />
            </div>
          </>
        );
      }}
    </TraceLoader>
    </div>
  );
}
