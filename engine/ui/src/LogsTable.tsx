import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { type KeyboardEvent, type PointerEvent, type RefObject, useEffect, useRef, useState } from "react";
import { ApiError, api } from "./api";
import { Icon } from "./icons";
import { type Explorer, type Json, type Log, type SearchResult, queryBody, queryIdentity, str } from "./Logs";
import { ColumnsMenu, columnLabel } from "@/components/ColumnsMenu";
import { Severity } from "@/components/Severity";
import { StateView } from "@/components/StateView";
import {
  DEFAULT_COLUMNS, DEFAULT_LAYOUT, LOCKED, MIN_WIDTH, autoFitWidth, clampWidth, moveColumn, reorderColumn,
  useLayout, visibleColumns, widthOf,
} from "@/lib/columns";
import { isOff, opOf, setOff } from "@/lib/attributes";
import { downloadCsv } from "@/lib/csv";
import { scopeBase } from "@/lib/scope";

const PER_PAGE = 50;
const COUNT_CAP = 10_000;
const EXPAND_W = 36;
const ADD_W = 44;
const HTTP_STATUS = /(^|\.)http\.status(_code)?$/;
const PLAIN_KEY = /^\w+(\.\w+)*$/;

type CursorT = { timestamp: string; id: string };

export const lookup = (obj: Record<string, Json>, path: string): Json => {
  // Engine returns flat dotted keys ("service.name") or nested; accept both.
  if (path in obj) return obj[path];
  return path.split(".").reduce<Json>((o, k) => (o && typeof o === "object" ? (o as Record<string, Json>)[k] : undefined), obj);
};

function cell(r: Log, path: string): string | null {
  const v = path.startsWith("attributes.") ? lookup(r.attributes, path.slice(11))
    : path.startsWith("resource.") ? lookup(r.resource, path.slice(9))
    : path.startsWith("exception.") ? (r.exception ? lookup(r.exception, path.slice(10)) : null)
    : path === "service.name" ? lookup(r.resource, "service.name")
    : path === "environment" ? lookup(r.resource, "deployment.environment.name") ?? lookup(r.resource, "environment")
    : path === "channel" ? r.channel
    : path === "severity.text" ? r.severity.text
    : path === "trace.id" ? r.trace_id
    : path === "span.id" ? r.span_id
    : null;
  return v == null ? null : str(v);
}

const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString();
const fmtClock = (d: Date) => d.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false });
const fmtTime = (d: Date) => d.toLocaleString("en-US", { month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false });

export function LogsTable({ s, refreshMs, hasFilters, exportRef }: { s: Explorer; refreshMs: number; hasFilters: boolean; exportRef: RefObject<(() => void) | null> }) {
  // Keyset pages already left behind. Keyed on the question so a new filter starts at page 1.
  const identity = queryIdentity(s);
  const [stack, setStack] = useState<{ identity: string; cursors: CursorT[] }>({ identity, cursors: [] });
  const cursors = stack.identity === identity ? stack.cursors : [];
  const cursor = cursors[cursors.length - 1];

  const search = useQuery({
    queryKey: ["logs", identity, cursor],
    queryFn: () =>
      api<SearchResult>(`${scopeBase(s.project)}/logs/search`, {
        method: "POST",
        body: { ...queryBody(s), sort: { field: "timestamp", direction: s.sort }, limit: PER_PAGE, ...(cursor ? { cursor } : {}) },
      }),
    placeholderData: keepPreviousData,
    // Page 2+ is a stable historical slice; polling it would only show the same rows.
    refetchInterval: refreshMs > 0 && cursors.length === 0 ? refreshMs : false,
  });
  const count = useQuery({
    queryKey: ["logs-count", identity],
    queryFn: () => api<{ count: number }>(`${scopeBase(s.project)}/logs/count`, { method: "POST", body: { ...queryBody(s), max: COUNT_CAP } }),
    enabled: !search.error,
    placeholderData: keepPreviousData,
    refetchInterval: refreshMs > 0 && cursors.length === 0 ? refreshMs : false,
  });

  const records = search.data?.records ?? [];
  const next = search.data?.next_cursor;
  const total = count.data?.count ?? 0;
  const capped = total >= COUNT_CAP;
  const err = search.error
    ? search.error instanceof ApiError && search.error.status === 422
      ? `Invalid query: ${search.error.message}`
      : search.error.message
    : null;
  const go = (c: CursorT[]) => setStack({ identity, cursors: c });
  const canPrev = cursors.length > 0;
  const canNext = !!next && !!search.data?.has_more && !search.isPlaceholderData;

  // ---- Layout -----------------------------------------------------------------
  const [layout, setLayout] = useLayout("logs");
  const cols = visibleColumns(s.columns, layout);
  const defaults: readonly string[] = DEFAULT_COLUMNS;
  const hiddenDefaults = defaults.filter((c) => layout.hidden.includes(c) && !LOCKED.has(c));
  const [menuOpen, setMenuOpen] = useState(false);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [guide, setGuide] = useState<{ x: number; w: number } | null>(null);
  const outerRef = useRef<HTMLDivElement>(null);
  const tableRef = useRef<HTMLTableElement>(null);

  const setWidth = (id: string, w: number) => setLayout((l) => ({ ...l, widths: { ...l.widths, [id]: clampWidth(w) } }));
  const toggle = (id: string, on: boolean) => {
    if (defaults.includes(id)) setLayout((l) => ({ ...l, hidden: on ? l.hidden.filter((c) => c !== id) : [...l.hidden, id] }));
    else if (on) s.addColumn(id);
    else s.removeColumn(id);
  };
  const setOrder = (ids: string[]) => setLayout((l) => ({ ...l, order: [...ids, ...l.order.filter((c) => !ids.includes(c))] }));
  const resetLayout = () => {
    setLayout(() => DEFAULT_LAYOUT);
    s.update((p) => p.delete("col"));
  };
  const fit = (id: string) => {
    let max = 0;
    tableRef.current?.querySelectorAll<HTMLElement>(`[data-fit="${id.replace(/"/g, '\\"')}"]`).forEach((el) => {
      const w = el.style.width;
      el.style.width = "max-content";
      max = Math.max(max, el.scrollWidth);
      el.style.width = w;
    });
    setWidth(id, autoFitWidth(max, 44));
  };
  const showGuide = (th: HTMLElement | null, w: number) =>
    setGuide(th && outerRef.current ? { x: th.getBoundingClientRect().left - outerRef.current.getBoundingClientRect().left + w, w } : null);

  const fixedSum = EXPAND_W + ADD_W + cols.filter((c) => c !== "message").reduce((n, c) => n + widthOf(layout, c), 0);
  const rowH = layout.density === "compact" ? 28 : 36;
  // Export what is on screen: the loaded page, visible columns, in order.
  exportRef.current = () =>
    downloadCsv([
      cols.map(columnLabel),
      ...records.map((r) => cols.map((c) => (c === "timestamp" ? r.timestamp : c === "level" ? r.severity.text : c === "message" ? r.message : cell(r, c) ?? ""))),
    ]);
  const open = (r: Log) => s.update((p) => { p.set("log", r.id); if (!s.project) p.set("logp", r.project_id); });
  const rangeText = err ? "—" : records.length === 0 ? "0 results" : `${(cursors.length * PER_PAGE + 1).toLocaleString("en-US")}–${(cursors.length * PER_PAGE + records.length).toLocaleString("en-US")} of ${total.toLocaleString("en-US")}${capped ? "+" : ""}`;
  const tbtn = "lh-control h-7 gap-1.5 border-transparent bg-transparent text-label font-medium";

  const pager = (
    <>
      <button type="button" className="lh-row-action" disabled={!canPrev} onClick={() => go(cursors.slice(0, -1))}>
        <Icon name="chevron-left" className="size-3.5" /><span className="sr-only">Previous page</span>
      </button>
      <button type="button" className="lh-row-action" disabled={!canNext} onClick={() => next && go([...cursors, next])}>
        <Icon name="chevron-right" className="size-3.5" /><span className="sr-only">Next page</span>
      </button>
    </>
  );

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      {/* Mobile header: count + sort, as before. */}
      <div className="flex items-center justify-between gap-3 px-4 py-2.5 md:hidden">
        <p className="text-body text-ink-muted" aria-live="polite">
          {err ? "—" : <><span className="tnum font-medium text-ink">{total.toLocaleString("en-US")}{capped && "+"}</span> {total === 1 && !capped ? "log" : "logs"}</>}
        </p>
        <button type="button" className="lh-control text-ink-muted" onClick={() => s.set("sort", s.sort === "desc" ? "asc" : "desc")}>
          <Icon name={s.sort === "desc" ? "chevron-down" : "chevron-up"} className="size-3.5" />
          <span>{s.sort === "desc" ? "Newest" : "Oldest"}</span>
        </button>
      </div>

      {err && <StateView kind="error" title="Unable to load logs" action={{ label: "Try again", onClick: () => search.refetch() }}>{err}</StateView>}

      <div className="mx-4 mb-4 hidden rounded-lg border border-line bg-surface md:block sm:mx-[30px]">
        <div className="flex h-11 items-center gap-2 border-b border-line px-3">
          <div className="relative">
            <button type="button" data-columns-trigger className={`${tbtn} bg-sunken`} aria-expanded={menuOpen} aria-haspopup="dialog" onClick={() => setMenuOpen((o) => !o)}>
              <Icon name="column" className="size-[13px]" />
              Columns
              <span className="tnum rounded-lg bg-ink px-[5px] py-px text-[10px] font-semibold text-surface">{cols.length}</span>
            </button>
            {menuOpen && (
              <ColumnsMenu
                scope={s}
                visible={cols}
                hidden={hiddenDefaults}
                onToggle={toggle}
                onMove={(id, d) => setOrder(moveColumn(cols, id, d))}
                onReorder={(id, t) => setOrder(reorderColumn(cols, id, t))}
                onReset={resetLayout}
                onClose={() => setMenuOpen(false)}
              />
            )}
          </div>
          <button type="button" className={tbtn} onClick={() => setLayout((l) => ({ ...l, density: l.density === "compact" ? "comfortable" : "compact" }))} title="Toggle row density">
            <Icon name="rows-3" className="size-[13px]" />
            {layout.density === "compact" ? "Compact" : "Comfortable"}
          </button>
          <button type="button" className={`${tbtn} ${layout.wrap ? "bg-brand-tint text-brand" : ""}`} aria-pressed={layout.wrap} onClick={() => setLayout((l) => ({ ...l, wrap: !l.wrap }))}>
            <Icon name="pilcrow" className="size-[13px]" />
            Wrap lines
          </button>
          <span className="flex-1" />
          <button type="button" className="text-label text-ink-muted hover:text-ink" onClick={resetLayout}>Reset layout</button>
          <span className="h-4 w-px bg-line" />
          <span className="tnum text-label text-ink-muted" aria-live="polite">{rangeText}</span>
          {pager}
        </div>

        <div ref={outerRef} className="relative">
          <div className="overflow-x-auto">
            <table ref={tableRef} className="border-collapse text-body" style={{ tableLayout: "fixed", width: "100%", minWidth: fixedSum + 240 }}>
              <colgroup>
                <col style={{ width: EXPAND_W }} />
                {cols.map((c) => <col key={c} style={c === "message" ? undefined : { width: widthOf(layout, c) }} />)}
                <col style={{ width: ADD_W }} />
              </colgroup>
              <thead>
                <tr className="h-[34px] border-b border-line bg-sunken text-left text-label text-ink-muted">
                  <th scope="col" className="border-r border-line"><span className="sr-only">Expand</span></th>
                  {cols.map((c) => {
                    const label = columnLabel(c);
                    return (
                      <th key={c} scope="col" aria-sort={c === "timestamp" ? (s.sort === "asc" ? "ascending" : "descending") : undefined} className="relative border-r border-line px-2.5 font-semibold select-none">
                        <span className="flex items-center gap-1.5">
                          {c === "timestamp" ? (
                            <button type="button" className="flex min-w-0 items-center gap-1 font-semibold hover:text-ink" onClick={() => s.set("sort", s.sort === "desc" ? "asc" : "desc")} title={`Sort by time, ${s.sort === "desc" ? "newest" : "oldest"} first`}>
                              <span data-fit={c} className="truncate">{label}</span>
                              <Icon name={s.sort === "desc" ? "arrow-down" : "arrow-up"} className="size-3 shrink-0 text-ink" />
                            </button>
                          ) : (
                            <span data-fit={c} className="truncate" title={c}>{label}</span>
                          )}
                          <span className="flex-1" />
                          {!LOCKED.has(c) && (
                            <button type="button" className="shrink-0 rounded p-0.5 text-ink-faint hover:text-ink" onClick={() => toggle(c, false)}>
                              <Icon name="close" className="size-[11px]" /><span className="sr-only">Remove column {label}</span>
                            </button>
                          )}
                        </span>
                        {c !== "message" && <Handle id={c} label={label} width={widthOf(layout, c)} onResize={setWidth} onFit={fit} onGuide={showGuide} />}
                      </th>
                    );
                  })}
                  <th scope="col" className="text-center">
                    <button type="button" data-columns-trigger className="lh-row-action" onClick={() => setMenuOpen((o) => !o)}>
                      <Icon name="plus" className="size-3.5 text-ink" /><span className="sr-only">Add column</span>
                    </button>
                  </th>
                </tr>
              </thead>
              <tbody>
                {records.map((r) => (
                  <Row
                    key={r.id}
                    r={r}
                    s={s}
                    cols={cols}
                    height={rowH}
                    wrap={layout.wrap}
                    expanded={expanded.has(r.id)}
                    onExpand={() => setExpanded((e) => { const n = new Set(e); n.has(r.id) ? n.delete(r.id) : n.add(r.id); return n; })}
                    onOpen={() => open(r)}
                    onToggleColumn={toggle}
                  />
                ))}
              </tbody>
            </table>
          </div>
          {guide && (
            <div className="pointer-events-none absolute inset-y-0 z-20" style={{ left: guide.x }}>
              <div className="h-full w-px bg-brand" />
              <div className="tnum absolute top-1 left-1/2 -translate-x-1/2 rounded bg-ink px-2 py-1 text-micro whitespace-nowrap text-surface">
                {guide.w} px · double-click to auto-fit
              </div>
            </div>
          )}
        </div>
      </div>

      <ul className="divide-y divide-border border-y border-border md:hidden" aria-label="Logs">
        {records.map((r) => {
          const service = lookup(r.resource, "service.name");
          return (
            <li key={r.id}>
              <button type="button" className="flex w-full flex-col gap-1 px-4 py-3 text-left active:bg-muted" onClick={() => open(r)}>
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <Severity n={r.severity.number} />
                  <time className="tnum text-label text-muted-foreground" dateTime={r.timestamp}>{fmtTime(new Date(r.timestamp))}</time>
                  {service != null && <span className="truncate text-label text-muted-foreground">{str(service)}</span>}
                </span>
                <span className="line-clamp-3 break-words font-mono text-body">{r.message}</span>
              </button>
            </li>
          );
        })}
      </ul>

      {records.length === 0 && !err && !search.isPending && <EmptyTable rangeEmpty={!hasFilters && !s.q} onClear={s.clearFilters} />}

      {(canPrev || search.data?.has_more) && (
        <div className="flex items-center justify-end gap-2 border-t border-line px-4 py-2.5 md:hidden">{pager}</div>
      )}
    </div>
  );
}

/** Drag (pointer capture), double-click (auto-fit) or ←/→ (±10px) to resize the column it sits on. */
function Handle({ id, label, width, onResize, onFit, onGuide }: {
  id: string;
  label: string;
  width: number;
  onResize: (id: string, w: number) => void;
  onFit: (id: string) => void;
  onGuide: (th: HTMLElement | null, w: number) => void;
}) {
  const start = useRef<{ x: number; w: number } | null>(null);
  const last = useRef(0);
  const guide = useRef(onGuide);
  guide.current = onGuide;
  // Don't leave the guide overlay stuck if the handle unmounts mid-drag.
  useEffect(() => () => { if (start.current) guide.current(null, 0); }, []);
  const th = (e: { currentTarget: HTMLElement }) => e.currentTarget.parentElement as HTMLElement;
  const move = (e: PointerEvent<HTMLElement>) => {
    if (!start.current) return;
    last.current = clampWidth(start.current.w + e.clientX - start.current.x);
    onGuide(th(e), last.current);
  };
  // The width is persisted once, on release; the guide shows the pending width meanwhile.
  const end = (commit: boolean) => {
    if (start.current && commit) onResize(id, last.current);
    start.current = null;
    onGuide(null, 0);
  };
  const key = (e: KeyboardEvent<HTMLElement>) => {
    const d = e.key === "ArrowLeft" ? -10 : e.key === "ArrowRight" ? 10 : 0;
    if (!d) return;
    e.preventDefault();
    onResize(id, th(e).getBoundingClientRect().width + d);
  };
  return (
    <div
      role="separator"
      aria-orientation="vertical"
      aria-label={`Resize column ${label}`}
      aria-valuenow={width}
      aria-valuemin={MIN_WIDTH}
      tabIndex={0}
      className="absolute inset-y-0 -right-1 z-10 w-2 cursor-col-resize touch-none hover:bg-brand/40 focus-visible:bg-brand/60 focus-visible:outline-none"
      onPointerDown={(e) => {
        if (e.button !== 0) return;
        e.currentTarget.setPointerCapture(e.pointerId);
        const w = th(e).getBoundingClientRect().width;
        start.current = { x: e.clientX, w };
        last.current = w;
        onGuide(th(e), w);
      }}
      onPointerMove={move}
      onPointerUp={() => end(true)}
      onPointerCancel={() => end(false)}
      onKeyDown={key}
      onDoubleClick={() => onFit(id)}
    />
  );
}

function Row({ r, s, cols, height, wrap, expanded, onExpand, onOpen, onToggleColumn }: {
  r: Log;
  s: Explorer;
  cols: string[];
  height: number;
  wrap: boolean;
  expanded: boolean;
  onExpand: () => void;
  onOpen: () => void;
  onToggleColumn: (id: string, on: boolean) => void;
}) {
  const existing = s.filters.find((f) => f.field === "message" && opOf(f) === "equals" && f.value === r.message);
  const filtered = !!existing && !isOff(existing);
  const filterLabel = filtered ? "Remove filter" : "Filter for this value";
  const td = `overflow-hidden px-2.5 ${wrap ? "py-1.5 align-top" : "py-0 align-middle"}`;
  const clip = wrap ? "block break-words whitespace-normal" : "block truncate";
  const render = (c: string) => {
    if (c === "timestamp") {
      const d = new Date(r.timestamp);
      return (
        <time data-fit={c} className={`tnum ${clip} text-micro text-ink-muted`} dateTime={r.timestamp}>
          {sameDay(d, new Date()) ? fmtClock(d) : fmtTime(d)}<span className="text-ink-faint">.{String(d.getMilliseconds()).padStart(3, "0")}</span>
        </time>
      );
    }
    if (c === "level") return <span data-fit={c} className="block"><Severity n={r.severity.number} pill /></span>;
    if (c === "message")
      return (
        <span className="flex min-w-0 items-center gap-1">
          {/* The row itself is mouse-only; this is the keyboard way in. */}
          <button type="button" className={`${clip} min-w-0 flex-1 text-left text-label text-ink focus-visible:outline-2`} onClick={(e) => { e.stopPropagation(); onOpen(); }}>
            {r.message}
          </button>
          <button
            type="button"
            className="lh-row-action shrink-0 opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
            title={filterLabel}
            onClick={(e) => {
              e.stopPropagation();
              const f = { field: "message", op: "equals", value: r.message };
              if (!existing) s.addFilter(f);
              else if (isOff(existing)) s.replaceFilter(existing, setOff(existing, false));
              else s.removeFilter(existing);
            }}
          >
            <Icon name={filtered ? "minus" : "plus"} className="size-3.5" />
            <span className="sr-only">{filterLabel}</span>
          </button>
        </span>
      );
    const v = cell(r, c);
    const bad = HTTP_STATUS.test(c) && Number(v) >= 500;
    return <span data-fit={c} className={`${clip} ${c === "service.name" ? "text-label font-medium text-ink" : bad ? "text-micro font-semibold text-error-ink" : "text-micro text-ink-muted"}`} title={v ?? ""}>{v ?? "—"}</span>;
  };

  const attrs = Object.entries(r.attributes).filter(([, v]) => v == null || typeof v !== "object");
  return (
    <>
      <tr
        style={{ height }}
        className={`group cursor-pointer border-b border-line transition-colors hover:bg-sunken ${expanded || s.log === r.id ? "bg-brand/5 hover:bg-brand/5" : ""}`}
        onClick={onOpen}
      >
        <td className="px-2 py-0 text-center">
          <button type="button" className="lh-row-action" aria-expanded={expanded} onClick={(e) => { e.stopPropagation(); onExpand(); }}>
            <Icon name={expanded ? "chevron-down" : "chevron-right"} className="size-3.5" />
            <span className="sr-only">{expanded ? "Hide attributes" : "Show attributes"}</span>
          </button>
        </td>
        {cols.map((c) => <td key={c} className={td}>{render(c)}</td>)}
        <td />
      </tr>
      {expanded && (
        <tr className="border-b border-line bg-sunken">
          <td colSpan={cols.length + 2} className="pt-3 pr-3 pb-3.5 pl-12">
            <div className="mb-2.5 flex items-center gap-3 text-[10px] font-semibold tracking-wide text-ink-muted uppercase">
              <span>Attributes · click a value to filter, pin to add as column</span>
              <button type="button" className="font-medium tracking-normal normal-case text-brand hover:underline" onClick={onOpen}>Details</button>
            </div>
            {attrs.length === 0 ? (
              <p className="text-body text-ink-muted">No attributes on this log.</p>
            ) : (
              <ul className="flex flex-wrap gap-2">
                {attrs.map(([k, v]) => {
                  const field = `attributes.${k}`;
                  const value = str(v ?? null);
                  const pinned = cols.includes(field);
                  return (
                    <li key={k} className={`group/pill flex h-[26px] items-center gap-1.5 rounded-md border bg-surface px-2 ${pinned ? "border-brand" : "border-line"}`}>
                      <span className="text-label text-ink-muted">{k}</span>
                      {PLAIN_KEY.test(k) ? (
                        <button type="button" className="max-w-60 truncate text-micro font-semibold text-ink hover:text-brand" title="Filter for this value" onClick={() => s.addFilter({ field, op: "equals", value })}>
                          {value}<span className="sr-only"> — filter for this value</span>
                        </button>
                      ) : (
                        <span className="max-w-60 truncate text-micro font-semibold text-ink">{value}</span>
                      )}
                      {!pinned && PLAIN_KEY.test(k) && <Icon name="funnel" className="size-[11px] text-ink-muted group-focus-within/pill:hidden group-hover/pill:hidden" />}
                      <button type="button" className={`lh-row-action p-0 ${pinned ? "text-brand" : "sr-only group-focus-within/pill:not-sr-only group-hover/pill:not-sr-only"}`} aria-pressed={pinned} title={pinned ? "Remove column" : "Add as column"} onClick={() => onToggleColumn(field, !pinned)}>
                        <Icon name="pin" className="size-[11px]" /><span className="sr-only">{pinned ? "Remove column" : "Add as column"} {k}</span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            )}
          </td>
        </tr>
      )}
    </>
  );
}

function EmptyTable({ rangeEmpty, onClear }: { rangeEmpty: boolean; onClear: () => void }) {
  return rangeEmpty ? (
    <StateView kind="empty" icon="inbox" title="No logs found">Widen the time range, or check that your application is sending logs.</StateView>
  ) : (
    <StateView kind="empty" icon="search" title="No logs match your filters" action={{ label: "Clear filters", onClick: onClear }}>
      Try removing a filter or clearing the search.
    </StateView>
  );
}
