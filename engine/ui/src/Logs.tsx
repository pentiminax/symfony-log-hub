import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { type ReactNode, useEffect, useLayoutEffect, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router";
import { ApiError, api } from "./api";
import { Severity, LEVELS, cap } from "@/components/Severity";
import { StateView } from "@/components/StateView";
import { resolveRange } from "@/lib/range";
import { scopeBase } from "@/lib/scope";
import { Icon } from "./icons";

// ---- Types & constants -------------------------------------------------------

type Json = unknown;
type Log = {
  id: string;
  project_id: string;
  timestamp: string;
  severity: { text: string; number: number };
  message: string;
  channel: string | null;
  resource: Record<string, Json>;
  attributes: Record<string, Json>;
  trace_id: string | null;
  span_id: string | null;
  exception: { type: string; message: string; stacktrace?: string | null } | null;
};
type SearchResult = { records: Log[]; next_cursor: { timestamp: string; id: string } | null; has_more: boolean };
type Filter = { field: string; op: string; value: string };

const PER_PAGE = 50;
const COUNT_CAP = 10_000;
const FACETS = [
  ["service.name", "service", "Service"],
  ["environment", "env", "Environment"],
  ["channel", "channel", "Channel"],
] as const;

const str = (v: Json) => (typeof v === "string" ? v : JSON.stringify(v));

// ---- URL state ---------------------------------------------------------------

function useExplorerState() {
  const [params, setParams] = useSearchParams();
  const get = (k: string) => params.get(k) ?? "";
  const filters: Filter[] = params.getAll("f").flatMap((raw) => {
    const [field, op, ...rest] = raw.split("|");
    return field && op ? [{ field, op, value: rest.join("|") }] : [];
  });
  const state = {
    project: get("project"),
    q: get("q"),
    range: get("range") || "24h",
    from: get("from"),
    to: get("to"),
    level: get("level"),
    service: get("service"),
    env: get("env"),
    channel: get("channel"),
    sort: get("sort") === "asc" ? "asc" : "desc",
    log: get("log"),
    logProject: get("logp"),
    columns: params.getAll("col"),
    filters,
  };
  /** Edit the query string; `replace` for keystroke-ish changes so Back stays useful. */
  const update = (fn: (p: URLSearchParams) => void, replace = false) =>
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        fn(next);
        return next;
      },
      { replace },
    );
  const set = (k: string, v: string, replace = false) =>
    update((p) => (v && !(k === "range" && v === "24h") && !(k === "sort" && v === "desc") ? p.set(k, v) : p.delete(k)), replace);
  const enc = (f: Filter) => `${f.field}|${f.op}|${f.value}`;
  const addFilter = (f: Filter) =>
    update((p) => {
      if (!p.getAll("f").includes(enc(f))) p.append("f", enc(f));
    });
  const removeFilter = (f: Filter) =>
    update((p) => {
      const rest = p.getAll("f").filter((x) => x !== enc(f));
      p.delete("f");
      rest.forEach((x) => p.append("f", x));
    });
  const clearFilters = () =>
    update((p) => ["f", "level", "service", "env", "channel", "q"].forEach((k) => p.delete(k)));
  const reset = () =>
    update((p) => ["f", "level", "service", "env", "channel", "q", "range", "from", "to", "sort", "log", "logp"].forEach((k) => p.delete(k)));
  const addColumn = (c: string) => update((p) => !p.getAll("col").includes(c) && p.append("col", c));
  const removeColumn = (c: string) =>
    update((p) => {
      const rest = p.getAll("col").filter((x) => x !== c);
      p.delete("col");
      rest.forEach((x) => p.append("col", x));
    });
  return { ...state, set, update, addFilter, removeFilter, clearFilters, reset, addColumn, removeColumn };
}
type Explorer = ReturnType<typeof useExplorerState>;

// ---- Engine query building ---------------------------------------------------

function engineFilters(s: Explorer) {
  const out: { field: string; operator: string; value?: unknown }[] = s.filters.map((f) => ({
    field: f.field,
    operator: f.op,
    value: f.value,
  }));
  const band = LEVELS.find((l) => l[0] === s.level)?.[1];
  if (band) out.push({ field: "severity", operator: "between", value: band });
  for (const [field, prop] of FACETS) if (s[prop]) out.push({ field, operator: "equals", value: s[prop] });
  return out;
}

const queryBody = (s: Explorer) => ({
  range: resolveRange(s),
  search: s.q || null,
  filters: engineFilters(s),
});

// ---- Page --------------------------------------------------------------------

export function Logs() {
  const s = useExplorerState();
  const [refreshMs, setRefreshMs] = useState(0);
  const [qInput, setQInput] = useState(s.q);

  // Debounced search box -> URL. External changes (Reset, back/forward) flow back in.
  useEffect(() => setQInput(s.q), [s.q]);
  useEffect(() => {
    const q = qInput.trim() === "" ? "" : qInput;
    // Compare the normalized value, or a whitespace-only box re-fires this every 400ms.
    if (q === s.q) return;
    const t = setTimeout(() => s.set("q", q, true), 400);
    return () => clearTimeout(t);
  });

  const hasFilters = s.filters.length > 0 || !!(s.level || s.service || s.env || s.channel);
  const sel = (id: string, label: string, value: string, prop: string, children: ReactNode, disabled = false) => (
    <>
      <label className="sr-only" htmlFor={id}>
        {label}
      </label>
      <select
        id={id}
        className={`lh-control pr-1.5 ${value ? "border-brand-line bg-brand-tint" : ""}`}
        value={value}
        disabled={disabled}
        onChange={(e) => s.set(prop, e.target.value)}
      >
        {children}
      </select>
    </>
  );

  return (
    <div className="flex min-h-full flex-col">
      <header className="flex flex-wrap items-center gap-3 px-4 pt-4 pb-3 sm:px-6">
        <h1 className="mr-auto text-lg font-semibold tracking-tight">Logs</h1>
        <label className="lh-control gap-1.5 pr-2.5" title="Auto-refresh">
          <span className="sr-only">Auto-refresh</span>
          <select className="bg-transparent text-body outline-none" value={refreshMs} onChange={(e) => setRefreshMs(Number(e.target.value))}>
            <option value={0}>Refresh: off</option>
            <option value={5000}>5s</option>
            <option value={15000}>15s</option>
            <option value={60000}>1m</option>
          </select>
        </label>
      </header>

      <div className="px-4 sm:px-6">
        <div className="relative">
          <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-faint">
            <Icon name="search" />
          </span>
          <input
            type="search"
            className="lh-input pl-9"
            value={qInput}
            onChange={(e) => setQInput(e.target.value)}
            placeholder="Search messages…"
            aria-label="Search log messages"
          />
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-6">
        {sel("lh-level", "Level", s.level, "level", (
          <>
            <option value="">All levels</option>
            {LEVELS.map(([v]) => <option key={v} value={v}>{cap(v)}</option>)}
          </>
        ))}
        {FACETS.map(([field, prop, label]) => (
          <FacetSelect key={prop} s={s} field={field} prop={prop} label={label} sel={sel} />
        ))}
        {s.filters.map((f) => (
          <span key={`${f.field}|${f.op}|${f.value}`} className="lh-control gap-1.5 border-brand-line bg-brand-tint pr-1.5 text-brand">
            <span className="font-mono text-micro">{f.field}</span>
            <span className="text-ink-muted">{f.op === "not_equals" ? "is not" : "is"}</span>
            <span className="max-w-40 truncate font-mono text-micro">{f.value}</span>
            <button type="button" className="shrink-0 rounded p-0.5 hover:bg-brand-line/40" onClick={() => s.removeFilter(f)}>
              <Icon name="close" className="size-3" />
              <span className="sr-only">Remove this filter</span>
            </button>
          </span>
        ))}
        {(hasFilters || s.q) && (
          <button type="button" className="lh-button lh-button-quiet" onClick={s.reset}>
            Reset
          </button>
        )}
      </div>

      <Results s={s} refreshMs={refreshMs} hasFilters={hasFilters} />
      {s.log && <Drawer s={s} />}
    </div>
  );
}

function FacetSelect({ s, field, prop, label, sel }: {
  s: Explorer;
  field: string;
  prop: "service" | "env" | "channel";
  label: string;
  sel: (id: string, label: string, value: string, prop: string, children: ReactNode, disabled?: boolean) => ReactNode;
}) {
  const facet = useQuery({
    queryKey: ["facet", s.project, field, s.range, s.from, s.to],
    queryFn: () => {
      const r = resolveRange(s);
      const qs = new URLSearchParams();
      if (r.from) qs.set("from", r.from);
      if (r.to) qs.set("to", r.to);
      return api<{ values: string[] }>(`${scopeBase(s.project)}/logs/facets/${field}?${qs}`);
    },
  });
  const values = facet.data?.values ?? [];
  const current = s[prop];
  return sel(`lh-${prop}`, label, current, prop, (
    <>
      <option value="">All {label}s</option>
      {current && !values.includes(current) && <option value={current}>{current}</option>}
      {values.map((v) => <option key={v} value={v}>{v}</option>)}
    </>
  ), values.length === 0 && !current);
}

// ---- Results table -----------------------------------------------------------

type CursorT = { timestamp: string; id: string };

function Results({ s, refreshMs, hasFilters }: { s: Explorer; refreshMs: number; hasFilters: boolean }) {
  // Keyset pages already left behind. Keyed on the question so a new filter starts at page 1.
  const identity = JSON.stringify([s.project, s.q, s.range, s.from, s.to, s.level, s.service, s.env, s.channel, s.filters, s.sort]);
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
  const th = "px-2 py-2 font-medium";

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 sm:px-6">
        <p className="text-body text-ink-muted" aria-live="polite">
          {err ? (
            "—"
          ) : (
            <>
              <span className="tnum font-medium text-ink">
                {total.toLocaleString("en-US")}
                {capped && "+"}
              </span>{" "}
              {total === 1 && !capped ? "log" : "logs"}
              {cursors.length > 0 && <span className="text-ink-faint"> · page <span className="tnum">{cursors.length + 1}</span></span>}
            </>
          )}
        </p>
        <div className="flex items-center gap-2">
          {search.isFetching && <span className="text-micro text-ink-faint">Updating…</span>}
          <button type="button" className="lh-control text-ink-muted" onClick={() => s.set("sort", s.sort === "desc" ? "asc" : "desc")} title={`Sort by time, ${s.sort === "desc" ? "newest" : "oldest"} first`}>
            <Icon name={s.sort === "desc" ? "chevron-down" : "chevron-up"} className="size-3.5" />
            <span>{s.sort === "desc" ? "Newest" : "Oldest"}</span>
          </button>
        </div>
      </div>

      {err && <StateView kind="error" title="Unable to load logs" action={{ label: "Try again", onClick: () => search.refetch() }}>{err}</StateView>}

      {/* relative: the rows' absolutely positioned sr-only labels must not overflow the page on narrow screens. */}
      <div className="relative hidden min-h-0 flex-1 overflow-x-auto border-t border-line md:block">
        <table className="w-full min-w-3xl border-collapse text-body">
          <thead className="sticky top-0 z-10 bg-canvas/95 backdrop-blur">
            <tr className="border-b border-line text-left text-label text-ink-muted">
              <th scope="col" className="w-48 px-4 py-2 font-medium sm:px-6">Time</th>
              <th scope="col" className={`w-24 ${th}`}>Level</th>
              <th scope="col" className={`w-40 ${th}`}>Service</th>
              <th scope="col" className={`w-32 ${th}`}>Channel</th>
              <th scope="col" className={th}>Message</th>
              {s.columns.map((c) => (
                <th key={c} scope="col" className={`w-40 ${th}`}>
                  <span className="flex items-center gap-1">
                    <span className="truncate font-mono" title={c}>{c}</span>
                    <button type="button" className="shrink-0 rounded p-0.5 text-ink-faint hover:text-ink" onClick={() => s.removeColumn(c)}>
                      <Icon name="close" className="size-3" />
                      <span className="sr-only">Remove column {c}</span>
                    </button>
                  </span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {records.map((r) => (
              <Row key={r.id} r={r} s={s} />
            ))}
          </tbody>
        </table>
      </div>

      <ul className="divide-y divide-border border-y border-border md:hidden" aria-label="Logs">
        {records.map((r) => {
          const service = lookup(r.resource, "service.name");
          return (
            <li key={r.id}>
              <button
                type="button"
                className="flex w-full flex-col gap-1 px-4 py-3 text-left active:bg-muted"
                onClick={() => s.update((p) => { p.set("log", r.id); if (!s.project) p.set("logp", r.project_id); })}
              >
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <Severity n={r.severity.number} />
                  <time className="tnum text-label text-muted-foreground" dateTime={r.timestamp}>
                    {new Date(r.timestamp).toLocaleString("en-US", { month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false })}
                  </time>
                  {service != null && <span className="truncate text-label text-muted-foreground">{str(service)}</span>}
                </span>
                <span className="line-clamp-3 break-words font-mono text-body">{r.message}</span>
              </button>
            </li>
          );
        })}
      </ul>

      {records.length === 0 && !err && !search.isPending && <EmptyTable rangeEmpty={!hasFilters && !s.q} onClear={s.clearFilters} />}

      {(search.data?.has_more || cursors.length > 0) && (
        <div className="flex items-center justify-end gap-2 border-t border-line px-4 py-2.5 sm:px-6">
          <button type="button" className="lh-button lh-button-quiet" disabled={cursors.length === 0} onClick={() => go(cursors.slice(0, -1))}>
            <Icon name="chevron-left" className="size-3.5" /> Previous
          </button>
          <button type="button" className="lh-button lh-button-quiet" disabled={!next || !search.data?.has_more || search.isPlaceholderData} onClick={() => next && go([...cursors, next])}>
            Next <Icon name="chevron-right" className="size-3.5" />
          </button>
        </div>
      )}
    </div>
  );
}

const lookup = (obj: Record<string, Json>, path: string): Json => {
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

function Row({ r, s }: { r: Log; s: Explorer }) {
  const d = new Date(r.timestamp);
  const service = lookup(r.resource, "service.name");
  const filtered = s.filters.some((f) => f.field === "message" && f.op === "equals" && f.value === r.message);
  const open = () => s.update((p) => { p.set("log", r.id); if (!s.project) p.set("logp", r.project_id); });
  const filterLabel = filtered ? "Remove filter" : "Filter for this value";
  return (
    <tr
      className={`group h-row cursor-pointer border-b border-line/70 align-middle transition-colors hover:bg-sunken ${s.log === r.id ? "bg-brand-tint hover:bg-brand-tint" : ""}`}
      onClick={open}
    >
      <td className="truncate px-4 py-0 sm:px-6">
        <time className="tnum text-ink-muted" dateTime={r.timestamp}>
          {d.toLocaleString("en-US", { month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false })}
          <span className="text-ink-faint">.{String(d.getMilliseconds()).padStart(3, "0")}</span>
        </time>
      </td>
      <td className="px-2 py-0"><Severity n={r.severity.number} /></td>
      <td className="max-w-40 truncate px-2 py-0 text-ink-muted" title={str(service ?? "")}>{service == null ? "—" : str(service)}</td>
      <td className="max-w-32 truncate px-2 py-0 text-ink-muted" title={r.channel ?? ""}>{r.channel ?? "—"}</td>
      <td className="max-w-0 py-0 pr-4 pl-2">
        <span className="flex min-w-0 items-center gap-1">
          {/* The row itself is mouse-only; this is the keyboard way in. */}
          <button type="button" className="block min-w-0 flex-1 truncate text-left font-mono text-ink focus-visible:outline-2" onClick={(e) => { e.stopPropagation(); open(); }}>
            {r.message}
          </button>
          <button
            type="button"
            className="lh-row-action shrink-0 opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
            title={filterLabel}
            onClick={(e) => {
              e.stopPropagation();
              const f = { field: "message", op: "equals", value: r.message };
              filtered ? s.removeFilter(f) : s.addFilter(f);
            }}
          >
            <Icon name={filtered ? "minus" : "plus"} className="size-3.5" />
            <span className="sr-only">{filterLabel}</span>
          </button>
        </span>
      </td>
      {s.columns.map((c) => {
        const v = cell(r, c);
        return <td key={c} className="max-w-40 truncate px-2 py-0 font-mono text-ink-muted" title={v ?? ""}>{v ?? "—"}</td>;
      })}
    </tr>
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

// ---- Detail drawer -----------------------------------------------------------

const TABS = [
  ["overview", "Overview"],
  ["attributes", "Attributes"],
  ["resource", "Resource"],
  ["exception", "Exception"],
  ["trace", "Trace"],
  ["raw", "Raw JSON"],
] as const;

function Copy({ text, children = "Copy value", icon = false }: { text: string; children?: ReactNode; icon?: boolean }) {
  const [done, setDone] = useState(false);
  return (
    <button
      type="button"
      className={`lh-row-action ${done ? "is-copied" : ""}`}
      title={icon ? "Copy value" : undefined}
      onClick={() => {
        navigator.clipboard?.writeText(text).then(() => {
          setDone(true);
          setTimeout(() => setDone(false), 1500);
        }, () => {});
      }}
    >
      <Icon name="copy" className="size-3.5" />
      {icon ? <span className="sr-only">{children}</span> : done ? "Copied" : children}
    </button>
  );
}

function Detail({ label, value, field, s, filterable = true }: { label: string; value: string; field?: string; s: Explorer; filterable?: boolean }) {
  return (
    <div className="group grid grid-cols-1 items-start gap-x-3 gap-y-0.5 border-b border-line/70 px-4 py-2 sm:grid-cols-[minmax(9rem,14rem)_1fr] sm:px-5">
      <span className="truncate font-mono text-micro text-ink-muted" title={label}>{label}</span>
      <span className="flex min-w-0 items-start gap-1">
        <span className="min-w-0 flex-1 font-mono break-words text-ink">{value}</span>
        <span className="flex shrink-0 items-center gap-0.5 transition-opacity sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100">
          <Copy text={value} icon />
          {field && filterable && (
            <>
              <button type="button" className="lh-row-action" title="Filter for this value" onClick={() => s.addFilter({ field, op: "equals", value })}>
                <Icon name="plus" className="size-3.5" /><span className="sr-only">Filter for this value</span>
              </button>
              <button type="button" className="lh-row-action" title="Filter out this value" onClick={() => s.addFilter({ field, op: "not_equals", value })}>
                <Icon name="minus" className="size-3.5" /><span className="sr-only">Filter out this value</span>
              </button>
            </>
          )}
          {field && (
            <>
              <button type="button" className="lh-row-action" title="Add as column" onClick={() => s.addColumn(field)}>
                <Icon name="column" className="size-3.5" /><span className="sr-only">Add as column</span>
              </button>
            </>
          )}
        </span>
      </span>
    </div>
  );
}

function Drawer({ s }: { s: Explorer }) {
  const ref = useRef<HTMLDialogElement>(null);
  const [tab, setTab] = useState<(typeof TABS)[number][0]>("overview");
  const close = () => s.update((p) => ["log", "logp"].forEach((k) => p.delete(k)));
  const closeRef = useRef(close);
  closeRef.current = close;

  const q = useQuery({
    queryKey: ["log", s.project || s.logProject, s.log],
    queryFn: () => api<Log>(`${scopeBase(s.project || s.logProject)}/logs/${s.log}`),
  });

  // showModal() gives the focus trap, top layer and focus restore. Layout effect: its cleanup runs
  // before React detaches the dialog, and close() only restores focus on a connected dialog.
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (!el.open) el.showModal();
    return () => el.close();
  }, []);
  useEffect(() => setTab("overview"), [s.log]);
  // The loading state's button unmounts when the log arrives; keep focus inside the dialog.
  useEffect(() => {
    const el = ref.current;
    if (el && !el.contains(document.activeElement)) el.focus();
  }, [q.isPending]);

  const r = q.data;
  const service = r && lookup(r.resource, "service.name");
  // Same fallback as the engine's environment column (record.rs).
  const env = r && (lookup(r.resource, "deployment.environment.name") ?? lookup(r.resource, "environment"));
  const nav = (e: React.KeyboardEvent, i: number) => {
    const j = e.key === "ArrowRight" ? (i + 1) % TABS.length : e.key === "ArrowLeft" ? (i + TABS.length - 1) % TABS.length : e.key === "Home" ? 0 : e.key === "End" ? TABS.length - 1 : -1;
    if (j < 0) return;
    e.preventDefault();
    setTab(TABS[j][0]);
    document.getElementById(`lh-tab-${TABS[j][0]}`)?.focus();
  };
  const kvs = (obj: Record<string, Json>, prefix: string, none: string) => {
    const keys = Object.keys(obj);
    return keys.length === 0 ? <p className="px-5 py-8 text-center text-body text-ink-muted">{none}</p> : keys.map((k) => (
      // The engine splits JSON paths on "." (so a flat "url.path" key can't match) and only takes scalars.
      <Detail key={k} s={s} label={k} value={str(obj[k])} field={prefix + k} filterable={/^\w+(\.\w+)*$/.test(k) && typeof obj[k] !== "object"} />
    ));
  };
  const raw = r && JSON.stringify({
    id: r.id, timestamp: r.timestamp, severity: r.severity, message: r.message, channel: r.channel,
    resource: r.resource, attributes: r.attributes,
    trace: r.trace_id ? { trace_id: r.trace_id, span_id: r.span_id } : null,
    exception: r.exception,
  }, null, 2);

  return (
    <dialog
      ref={ref}
      aria-label="Log details"
      tabIndex={-1}
      className="lh-drawer m-0 ml-auto h-dvh max-h-dvh w-full max-w-none border-l border-line bg-surface p-0 text-ink backdrop:bg-black/25 sm:w-[46rem] sm:max-w-[92vw]"
      onCancel={(e) => { e.preventDefault(); close(); }}
      onClick={(e) => e.target === ref.current && close()}
    >
      {!r ? (
        <div className="flex h-full flex-col items-center justify-center gap-3 px-8 text-center">
          {q.isPending ? <p className="text-body text-ink-muted">Loading…</p> : (
            <>
              <p className="text-md font-medium">This log is no longer available</p>
              <p className="max-w-xs text-body text-ink-muted">It may have been removed by retention, or it belongs to another project.</p>
            </>
          )}
          <button type="button" className="lh-button lh-button-quiet mt-1" onClick={close}>Close</button>
        </div>
      ) : (
        <div className="flex h-full flex-col">
          <div className="flex items-start gap-3 border-b border-line px-5 py-4">
            <span className="mt-0.5 shrink-0"><Severity n={r.severity.number} /></span>
            <div className="min-w-0 flex-1">
              <p className="font-mono text-md break-words text-ink">{r.message}</p>
              <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-micro text-ink-muted">
                <time className="tnum" dateTime={r.timestamp}>{r.timestamp}</time>
                {r.channel && <span className="font-mono">{r.channel}</span>}
              </p>
            </div>
            <button type="button" className="lh-button lh-button-quiet shrink-0 px-2" onClick={close}>
              <Icon name="close" /><span className="sr-only">Close</span>
            </button>
          </div>

          <div className="overflow-x-auto border-b border-line px-3">
            <div className="flex gap-1" role="tablist" aria-label="Log details">
              {TABS.map(([k, label], i) => (
                <button key={k} type="button" role="tab" id={`lh-tab-${k}`} className="lh-tab" aria-controls={`lh-panel-${k}`}
                  aria-selected={tab === k} tabIndex={tab === k ? 0 : -1} onClick={() => setTab(k)} onKeyDown={(e) => nav(e, i)}>
                  {label}
                </button>
              ))}
            </div>
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto" role="tabpanel" id={`lh-panel-${tab}`} aria-labelledby={`lh-tab-${tab}`}>
            {tab === "overview" && (
              <>
                <Detail s={s} label="timestamp" value={r.timestamp} />
                <Detail s={s} label="severity.text" value={r.severity.text} field="severity.text" />
                <Detail s={s} label="severity.number" value={String(r.severity.number)} />
                <Detail s={s} label="message" value={r.message} />
                <Detail s={s} label="channel" value={r.channel ?? "—"} field={r.channel ? "channel" : undefined} />
                <Detail s={s} label="service.name" value={str(service ?? "—")} field={service == null ? undefined : "service.name"} />
                <Detail s={s} label="environment" value={str(env ?? "—")} field={env == null ? undefined : "environment"} />
                <Detail s={s} label="log.id" value={r.id} />
              </>
            )}
            {tab === "attributes" && kvs(r.attributes, "attributes.", "No attributes on this log.")}
            {tab === "resource" && kvs(r.resource, "resource.", "No resource attributes on this log.")}
            {tab === "exception" &&
              (r.exception ? (
                <>
                  <Detail s={s} label="exception.type" value={r.exception.type} field="exception.type" />
                  <Detail s={s} label="exception.message" value={r.exception.message} />
                  {r.exception.stacktrace && (
                    <div className="px-5 py-3">
                      <div className="mb-1.5 flex items-center justify-between">
                        <span className="font-mono text-micro text-ink-muted">exception.stacktrace</span>
                        <Copy text={r.exception.stacktrace}>Copy</Copy>
                      </div>
                      <pre className="max-h-96 overflow-auto rounded-control bg-sunken p-3 font-mono text-micro leading-relaxed text-ink-muted">{r.exception.stacktrace}</pre>
                    </div>
                  )}
                </>
              ) : (
                <p className="px-5 py-8 text-center text-body text-ink-muted">This log carries no exception.</p>
              ))}
            {tab === "trace" &&
              (r.trace_id ? (
                <>
                  <Detail s={s} label="trace.id" value={r.trace_id} field="trace.id" />
                  <Detail s={s} label="span.id" value={r.span_id ?? "—"} field={r.span_id ? "span.id" : undefined} />
                  <p className="px-5 py-3">
                    <Link className="text-brand underline" to={`/traces/${r.trace_id}?${new URLSearchParams({ trace_project: r.project_id, ...(s.project && { project: s.project }), ...(s.range !== "24h" && { range: s.range }), ...(s.from && { from: s.from }), ...(s.to && { to: s.to }) })}`}>View this trace</Link>
                  </p>
                </>
              ) : (
                <p className="px-5 py-8 text-center text-body text-ink-muted">This log is not part of a trace.</p>
              ))}
            {tab === "raw" && (
              <div className="px-5 py-3">
                <div className="mb-1.5 flex justify-end"><Copy text={raw ?? ""}>Copy JSON</Copy></div>
                <pre className="overflow-auto rounded-control bg-sunken p-3 font-mono text-micro leading-relaxed text-ink-muted">{raw}</pre>
              </div>
            )}
          </div>
        </div>
      )}
    </dialog>
  );
}
