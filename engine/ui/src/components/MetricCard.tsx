import type { ReactNode } from "react";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/** LogHub / Metric card: label, big tabular value, one line of context. */
export function MetricCard({ label, value, hint, loading }: { label: string; value: ReactNode; hint?: ReactNode; loading?: boolean }) {
  return (
    <Card className="gap-1 px-4 py-3">
      <p className="text-label text-muted-foreground">{label}</p>
      {loading ? <Skeleton className="my-1 h-7 w-20" /> : <p className="tnum text-xl font-semibold tracking-tight">{value}</p>}
      {hint && <p className="text-micro text-muted-foreground">{hint}</p>}
    </Card>
  );
}
