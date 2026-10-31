import { useCallback, useState } from "react";

export const DEFAULT_COLUMNS = ["timestamp", "level", "service.name", "message"] as const;
/** Cannot be hidden. */
export const LOCKED = new Set(["timestamp", "level", "message"]);
export const MIN_WIDTH = 60;
export const MAX_WIDTH = 640;
export const DEFAULT_WIDTH = 128;
export const DEFAULT_WIDTHS: Record<string, number> = { timestamp: 168, level: 84, "service.name": 140 };

export type Layout = {
  order: string[];
  hidden: string[];
  widths: Record<string, number>;
  density: "comfortable" | "compact";
  wrap: boolean;
};

export const DEFAULT_LAYOUT: Layout = { order: [], hidden: [], widths: {}, density: "comfortable", wrap: false };

export const clampWidth = (w: number) => Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, Math.round(w)));
/** Content width plus cell padding, clamped. */
export const autoFitWidth = (content: number, pad = 20) => clampWidth(content + pad);
export const widthOf = (l: Layout, id: string) => l.widths[id] ?? DEFAULT_WIDTHS[id] ?? DEFAULT_WIDTH;

/** Columns on screen: defaults + URL columns, ordered by the saved order, minus hidden (never a locked one). */
export function visibleColumns(userCols: string[], l: Layout): string[] {
  const all = [...DEFAULT_COLUMNS, ...userCols.filter((c) => !(DEFAULT_COLUMNS as readonly string[]).includes(c))];
  const pos = (c: string) => {
    const i = l.order.indexOf(c);
    return i < 0 ? Infinity : i;
  };
  return all
    .map((c, i) => [c, i] as const)
    .sort((a, b) => pos(a[0]) - pos(b[0]) || a[1] - b[1])
    .map(([c]) => c)
    .filter((c) => LOCKED.has(c) || !l.hidden.includes(c));
}

/** Move `id` by `delta` places within `ids` (clamped); returns a new array. */
export function moveColumn(ids: string[], id: string, delta: number): string[] {
  const from = ids.indexOf(id);
  if (from < 0) return ids;
  const to = Math.min(ids.length - 1, Math.max(0, from + delta));
  const out = ids.filter((c) => c !== id);
  out.splice(to, 0, id);
  return out;
}

/** Drag-and-drop variant: put `id` where `target` currently is. */
export function reorderColumn(ids: string[], id: string, target: string): string[] {
  const from = ids.indexOf(id);
  const to = ids.indexOf(target);
  return from < 0 || to < 0 ? ids : moveColumn(ids, id, to - from);
}

const strings = (v: unknown): string[] => (Array.isArray(v) ? v.filter((x): x is string => typeof x === "string") : []);

/** Defensive parse of whatever localStorage holds. */
export function parseLayout(raw: string | null): Layout {
  try {
    const j = raw ? JSON.parse(raw) : null;
    if (!j || typeof j !== "object") return DEFAULT_LAYOUT;
    const widths: Record<string, number> = {};
    for (const [k, v] of Object.entries(j.widths ?? {})) if (typeof v === "number" && Number.isFinite(v)) widths[k] = clampWidth(v);
    return {
      order: strings(j.order),
      hidden: strings(j.hidden).filter((c) => !LOCKED.has(c)),
      widths,
      density: j.density === "compact" ? "compact" : "comfortable",
      wrap: j.wrap === true,
    };
  } catch {
    return DEFAULT_LAYOUT;
  }
}

const key = (page: string) => `loghub.layout.${page}`;

/** Layout persisted per page; storage failures (private mode) just mean nothing is remembered. */
export function useLayout(page: string) {
  const [layout, setLayout] = useState<Layout>(() => {
    try {
      return parseLayout(localStorage.getItem(key(page)));
    } catch {
      return DEFAULT_LAYOUT;
    }
  });
  const update = useCallback(
    (fn: (l: Layout) => Layout) =>
      setLayout((prev) => {
        const next = fn(prev);
        try {
          localStorage.setItem(key(page), JSON.stringify(next));
        } catch {
          /* ignore */
        }
        return next;
      }),
    [page],
  );
  return [layout, update] as const;
}
