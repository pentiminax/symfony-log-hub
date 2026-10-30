import type { ReactNode } from "react";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/** LogHub / Metric card: label, big tabular value, one line of context. */
export function MetricCard({ label, value, hint, loading }: { label: string; value: ReactNode; hint?: ReactNode; loading?: boolean }) {
  return (
    <Card className="gap-2.5 rounded-lg border border-border p-[18px] ring-0">
      <p className="text-body font-medium text-muted-foreground">{label}</p>
      {loading ? <Skeleton className="h-[31px] w-24" /> : <p className="tnum text-[25px] leading-tight font-bold">{value}</p>}
      {hint && <p className="text-micro text-muted-foreground">{hint}</p>}
    </Card>
  );
}
