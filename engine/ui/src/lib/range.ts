export const RANGES = [
  ["15m", "Last 15 minutes", 15 * 60e3],
  ["1h", "Last 1 hour", 3600e3],
  ["24h", "Last 24 hours", 24 * 3600e3],
  ["7d", "Last 7 days", 7 * 24 * 3600e3],
  ["custom", "Custom range", 0],
] as const;

export const DEFAULT_RANGE = "24h";

type RangeParams = { range: string; from?: string; to?: string };

/** Resolve the `?range=` preset (or custom from/to) into engine `range` bounds. */
export function resolveRange({ range, from = "", to = "" }: RangeParams) {
  const iso = (v: string) => {
    const d = new Date(v);
    return v && !Number.isNaN(d.getTime()) ? d.toISOString() : null;
  };
  if (range === "custom") return { from: iso(from), to: iso(to) };
  const ms = RANGES.find((r) => r[0] === range)?.[2] || 24 * 3600e3;
  const now = Date.now();
  return { from: new Date(now - ms).toISOString(), to: new Date(now).toISOString() };
}

export const rangeLabel = (range: string) => RANGES.find((r) => r[0] === range)?.[1] ?? "Last 24 hours";

/** Histogram bucket size (seconds) giving ~24-48 bars for a preset. */
export const bucketSeconds = (range: string) => ({ "15m": 30, "1h": 120, "24h": 1800, "7d": 6 * 3600 })[range] ?? 1800;
