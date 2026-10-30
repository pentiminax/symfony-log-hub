import { useLayoutEffect, useRef, useState } from "react";
import { Link } from "react-router";
import { SpanDetails, TraceLoader, TraceWaterfall, traceStats } from "@/components/TraceWaterfall";
import { Badge } from "@/components/ui/badge";
import { Icon } from "@/icons";
import { formatDuration, isError } from "@/traceTree";

const TABS = ["Waterfall", "Attributes", "Related logs"] as const;
const Tile = ({ label, value }: { label: string; value: string }) => (
  <div className="flex flex-1 flex-col gap-[7px] rounded-[7px] border border-border bg-sidebar p-3.5">
    <span className="text-[10px] font-semibold text-muted-foreground">{label}</span>
    <span className="tnum text-[15px] font-semibold">{value}</span>
  </div>
);

/** Off-canvas trace details: a modal <dialog> pinned right, same pattern as the log drawer. */
export function TraceSheet({ project, traceId, onClose }: { project: string; traceId: string; onClose: () => void }) {
  const ref = useRef<HTMLDialogElement>(null);
  const [tab, setTab] = useState<(typeof TABS)[number]>("Waterfall");
  // showModal() gives the focus trap, top layer and focus restore; the layout-effect cleanup closes it before React detaches it.
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (!el.open) el.showModal();
    return () => el.close();
  }, []);

  return (
    <dialog
      ref={ref}
      aria-label="Trace details"
      tabIndex={-1}
      className="lh-drawer m-0 ml-auto h-dvh max-h-dvh w-full max-w-none border-l border-border bg-sidebar p-0 text-foreground backdrop:bg-[#09090B66] sm:w-[640px] sm:max-w-[92vw]"
      onCancel={(e) => { e.preventDefault(); onClose(); }}
      onClick={(e) => e.target === ref.current && onClose()}
    >
      <TraceLoader project={project} traceId={traceId}>
        {(spans) => {
          const { root, totalNs, errors } = traceStats(spans);
          const failed = spans.find(isError);
          const logs = new URLSearchParams({ project, range: "7d" });
          logs.append("f", `trace.id|equals|${traceId}`);
          return (
            <div className="flex h-full flex-col">
              <div className="flex items-center justify-between gap-3 border-b border-border px-6 py-[22px]">
                <div className="flex min-w-0 flex-col gap-1.5">
                  <span className="text-[10px] font-semibold text-muted-foreground">TRACE DETAILS</span>
                  <h2 className="truncate text-xl font-bold">{root.name}</h2>
                </div>
                <button type="button" className="shrink-0 text-muted-foreground hover:text-foreground" onClick={onClose}>
                  <Icon name="close" className="size-5" /><span className="sr-only">Close</span>
                </button>
              </div>
              <div className="flex flex-1 flex-col gap-[22px] overflow-y-auto p-6">
                <div className="flex items-center gap-2.5 text-xs text-muted-foreground">
                  <span>Trace ID <span className="font-mono">{traceId.slice(0, 8)}</span></span>
                  <button type="button" onClick={() => navigator.clipboard?.writeText(traceId)} aria-label="Copy trace ID" className="hover:text-foreground">
                    <Icon name="copy" className="size-3.5" />
                  </button>
                  {errors > 0 ? <Badge className="bg-error-tint text-error-ink">Error</Badge> : <Badge variant="secondary">OK</Badge>}
                </div>
                <div className="flex gap-3">
                  <Tile label="DURATION" value={formatDuration(totalNs)} />
                  <Tile label="SPANS" value={String(spans.length)} />
                  <Tile label="STARTED" value={new Date(root.start_time).toLocaleTimeString([], { hour12: false })} />
                </div>
                <div className="flex gap-[22px] border-b border-border" role="tablist" aria-label="Trace details">
                  {TABS.map((t) => (
                    <button key={t} type="button" role="tab" aria-selected={tab === t} onClick={() => setTab(t)}
                      className={`-mb-px border-b-2 pb-3 text-[13px] ${tab === t ? "border-brand font-semibold text-brand" : "border-transparent text-muted-foreground hover:text-foreground"}`}>
                      {t}
                    </button>
                  ))}
                </div>
                {tab === "Waterfall" && (
                  <>
                    <div className="flex items-center justify-between">
                      <h3 className="text-sm font-semibold">Span waterfall</h3>
                      <span className="tnum text-xs text-muted-foreground">Total {formatDuration(totalNs)}</span>
                    </div>
                    <TraceWaterfall spans={spans} />
                    {failed && (
                      <div className="flex flex-col gap-2 rounded-[7px] border border-[#FECDCA] bg-error-tint p-4 text-error-ink">
                        <p className="text-[13px] font-semibold">{failed.status.message || failed.name}</p>
                        <p className="text-xs">{failed.service_name} &middot; {failed.name}</p>
                      </div>
                    )}
                  </>
                )}
                {tab === "Attributes" && <div><SpanDetails span={root} /></div>}
                {tab === "Related logs" && (
                  <Link className="text-body text-brand underline" to={`/logs?${logs}`}>View logs for this trace</Link>
                )}
              </div>
            </div>
          );
        }}
      </TraceLoader>
    </dialog>
  );
}
