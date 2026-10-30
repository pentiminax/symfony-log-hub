import type { ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Icon } from "@/icons";
import { cn } from "@/lib/utils";

type Props = {
  kind: "loading" | "error" | "empty";
  title?: string;
  children?: ReactNode;
  icon?: Parameters<typeof Icon>[0]["name"];
  /** Retry (error) or the contextual recovery action (empty). */
  action?: { label: string; onClick: () => void };
  className?: string;
};

/** The three panel states from the design: loading skeleton, error with retry, contextual empty. */
export function StateView({ kind, title, children, icon, action, className }: Props) {
  const loading = kind === "loading";
  return (
    <div
      role={kind === "error" ? "alert" : loading ? "status" : undefined}
      aria-busy={loading || undefined}
      className={cn("mx-auto flex max-w-sm flex-col items-center gap-2 px-6 py-12 text-center", className)}
    >
      <span
        className={cn(
          "flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground",
          kind === "error" && "bg-error-tint text-error-ink",
        )}
      >
        <Icon name={icon ?? (kind === "error" ? "alert" : "inbox")} className={cn("size-5", loading && "animate-pulse")} />
      </span>
      <p className="text-md font-medium">{title ?? (loading ? "Loading data" : kind === "error" ? "Unable to load data" : "Nothing here yet")}</p>
      {loading ? (
        <>
          <p className="sr-only">Please wait</p>
          <Skeleton className="h-3 w-48" />
        </>
      ) : (
        children && <p className="text-body text-muted-foreground">{children}</p>
      )}
      {action && (
        <Button variant="outline" className="mt-2" onClick={action.onClick}>
          {action.label}
        </Button>
      )}
    </div>
  );
}
