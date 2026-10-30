import { memo } from "react";

export type Bucket = { t: string; total: number; errors: number };

/** Bars sized from real bucket counts; the error share is stacked at the bottom. Pure CSS: no chart dependency. */
export const Histogram = memo(function Histogram({ buckets, label }: { buckets: Bucket[]; label: string }) {
  const max = Math.max(1, ...buckets.map((b) => b.total));
  return (
    <div role="img" aria-label={label} className="flex h-32 items-end gap-px">
      {buckets.map((b) => (
        <div
          key={b.t}
          title={`${new Date(b.t).toLocaleString()}: ${b.total} events, ${b.errors} errors`}
          className="flex min-w-px flex-1 flex-col justify-end bg-brand-tint"
          style={{ height: `${Math.max(2, (b.total / max) * 100)}%` }}
        >
          {b.errors > 0 && <div className="bg-error-dot" style={{ height: `${(b.errors / b.total) * 100}%` }} />}
        </div>
      ))}
    </div>
  );
});
