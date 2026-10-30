import { memo } from "react";

export type Bucket = { t: string; total: number; errors: number };

const LABELS = 7;

/** Bars sized from real bucket counts, with evenly spaced time labels underneath. Pure CSS: no chart dependency. */
export const Histogram = memo(function Histogram({ buckets, label }: { buckets: Bucket[]; label: string }) {
  const max = Math.max(1, ...buckets.map((b) => b.total));
  const span = Date.parse(buckets[buckets.length - 1].t) - Date.parse(buckets[0].t);
  const fmt = (t: string) =>
    new Date(t).toLocaleString(undefined, span > 36e5 * 36 ? { month: "short", day: "numeric" } : { hour: "2-digit", minute: "2-digit", hour12: false });
  const ticks = Array.from({ length: LABELS - 1 }, (_, i) => fmt(buckets[Math.floor((i * buckets.length) / (LABELS - 1))].t)).concat("Now");
  return (
    <div role="img" aria-label={label} className="flex flex-col gap-3">
      <div className="flex h-[135px] items-end gap-0.5">
        {buckets.map((b, i) => (
          <div
            key={b.t}
            title={`${new Date(b.t).toLocaleString()}: ${b.total} events, ${b.errors} errors`}
            className={"min-w-px flex-1 rounded-t-sm " + (i === buckets.length - 1 ? "bg-[#1D4ED8]" : "bg-[#C8D8FA]")}
            style={{ height: `${Math.max(2, (b.total / max) * 100)}%` }}
          />
        ))}
      </div>
      <div className="flex justify-between text-[11px] text-muted-foreground">
        {ticks.map((t, i) => <span key={i}>{t}</span>)}
      </div>
    </div>
  );
});
