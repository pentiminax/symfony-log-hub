export type Json = string | number | boolean | null | Json[] | { [k: string]: Json };

export type Span = {
  trace_id: string;
  span_id: string;
  parent_span_id: string | null;
  name: string;
  kind: string;
  start_time: string;
  end_time: string;
  duration_ns: number;
  status: { code: string; message: string | null };
  service_name: string;
  environment: string | null;
  scope: { name: string; version: string | null };
  resource: Record<string, Json>;
  attributes: Record<string, Json>;
  events: { name: string; timestamp: string | null; attributes: Record<string, Json> }[];
};

export type Row = { span: Span; depth: number };

/** ISO timestamp -> microseconds since epoch (Date alone truncates to ms). */
export const toUs = (iso: string): number => {
  const ms = Date.parse(iso);
  const frac = /\.(\d+)/.exec(iso)?.[1] ?? "";
  return ms * 1000 + (frac.length > 3 ? Number(frac.slice(3, 6).padEnd(3, "0")) : 0);
};

export const isError = (s: Span) => s.status.code === "error";

/**
 * Depth-first, children in input order (the engine returns spans ordered by start).
 * A span whose parent is missing from the trace attaches at root level; parent cycles
 * (malformed data) are shown rather than lost. Port of Trace::tree() in the Symfony app.
 */
export function buildTree(spans: Span[]): Row[] {
  const ids = new Set(spans.map((s) => s.span_id));
  const children = new Map<string, Span[]>();
  const roots: Span[] = [];
  for (const s of spans) {
    if (s.parent_span_id && ids.has(s.parent_span_id) && s.parent_span_id !== s.span_id) {
      const list = children.get(s.parent_span_id);
      if (list) list.push(s);
      else children.set(s.parent_span_id, [s]);
    } else roots.push(s);
  }
  const rows: Row[] = [];
  const seen = new Set<string>();
  // Explicit stack: traces can be 10000 spans deep, which overflows a recursive walk.
  const walk = (root: Span, rootDepth: number) => {
    const stack: Row[] = [{ span: root, depth: rootDepth }];
    for (let row = stack.pop(); row; row = stack.pop()) {
      if (seen.has(row.span.span_id)) continue;
      seen.add(row.span.span_id);
      rows.push(row);
      const kids = children.get(row.span.span_id) ?? [];
      for (let i = kids.length - 1; i >= 0; i--) stack.push({ span: kids[i], depth: row.depth + 1 });
    }
  };
  roots.forEach((r) => walk(r, 0));
  spans.forEach((s) => walk(s, 0));
  return rows;
}

/** Bar geometry as percentages of the trace; min 0.4% width so tiny spans stay visible. */
export function geometry(spans: Span[]) {
  const starts = spans.map((s) => toUs(s.start_time));
  const start = Math.min(...starts);
  const total = Math.max(...spans.map((s, i) => starts[i] + Math.floor(s.duration_ns / 1000))) - start;
  const bar = (s: Span) => {
    const width = total === 0 ? 100 : Math.max(0.4, Math.floor(s.duration_ns / 1000) / total * 100);
    const left = total === 0 ? 0 : Math.min(100 - width, ((toUs(s.start_time) - start) / total) * 100);
    return { left, width };
  };
  return { start, total, bar };
}

/** Stable hue per service (djb2; the Symfony app used crc32, hues are not meant to match). */
export const serviceHue = (name: string) => {
  let h = 5381;
  for (const c of name) h = (h * 33 + c.charCodeAt(0)) >>> 0;
  return h % 360;
};

export function formatDuration(ns: number): string {
  if (ns >= 1e9) return `${(ns / 1e9).toFixed(2)} s`;
  if (ns >= 1e6) return `${(ns / 1e6).toFixed(ns >= 1e8 ? 0 : 1)} ms`;
  if (ns >= 1e3) return `${Math.round(ns / 1e3)} µs`;
  return `${ns} ns`;
}
