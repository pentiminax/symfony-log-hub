import { useIsFetching, useQuery } from "@tanstack/react-query";
import { type ReactNode, useEffect, useLayoutEffect, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router";
import { type Project, api } from "./api";
import { AddFilter, FilterChip } from "@/components/FilterChip";
import { QueryBar } from "@/components/QueryBar";
import { Severity } from "@/components/Severity";
import { resolveRange } from "@/lib/range";
import { scopeBase } from "@/lib/scope";
import { Icon } from "./icons";
import { LogsTable, lookup } from "./LogsTable";
import { type Filter, decodeFilter, encodeFilter, toEngine } from "@/lib/attributes";

// ---- Types & constants -------------------------------------------------------

export type Json = unknown;
export type Log = {
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
export type SearchResult = { records: Log[]; next_cursor: { timestamp: string; id: string } | null; has_more: boolean };

const LIVE_MS = 5000;
/** Pre-chip URL params: still honoured, shown as chips; editing one moves it into `f`. */
const LEGACY = [
  ["level", "level"],
  ["service", "service.name"],
  ["env", "environment"],
  ["channel", "channel"],
] as const;

export const str = (v: Json) => (typeof v === "string" ? v : JSON.stringify(v));

// ---- URL state ---------------------------------------------------------------

function useExplorerState() {
  const [params, setParams] = useSearchParams();
  const get = (k: string) => params.get(k) ?? "";
  const filters: Filter[] = params.getAll("f").flatMap((raw) => decodeFilter(raw) ?? []);
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
    legacy: LEGACY.flatMap(([k, field]) => (get(k) ? [{ field, op: "equals", value: get(k) }] : [])),
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
  const enc = encodeFilter;
  const addFilter = (f: Filter) =>
    update((p) => {
      if (!p.getAll("f").includes(enc(f))) p.append("f", enc(f));
    });
  const dropLegacy = (p: URLSearchParams, f: Filter) => {
    const k = LEGACY.find(([key, field]) => field === f.field && p.get(key) === f.value)?.[0];
    if (k) p.delete(k);
  };
  const removeFilter = (f: Filter) =>
    update((p) => {
      const all = p.getAll("f");
      if (!all.includes(enc(f))) return dropLegacy(p, f);
      p.delete("f");
      all.filter((x) => x !== enc(f)).forEach((x) => p.append("f", x));
    });
  const replaceFilter = (old: Filter, next: Filter) =>
    update((p) => {
      const all = p.getAll("f");
      if (!all.includes(enc(old))) {
        dropLegacy(p, old);
        return p.append("f", enc(next));
      }
      p.delete("f");
      all.forEach((x) => p.append("f", x === enc(old) ? enc(next) : x));
    });
  const clearFilterChips = () => update((p) => ["f", ...LEGACY.map(([k]) => k)].forEach((k) => p.delete(k)));
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
  return { ...state, set, update, addFilter, removeFilter, replaceFilter, clearFilterChips, clearFilters, reset, addColumn, removeColumn };
}
export type Explorer = ReturnType<typeof useExplorerState>;

// ---- Engine query building ---------------------------------------------------

export function engineFilters(s: Explorer) {
  // Legacy params go through the same conversion as chips (level -> severity band, the rest equals).
  return [...s.legacy, ...s.filters].flatMap((f) => toEngine(f) ?? []);
}

/** Identity of the engine-facing question: toggling a disabled or invalid chip must not reset paging. */
export const queryIdentity = (s: Explorer) => JSON.stringify([s.project, s.q, s.range, s.from, s.to, s.sort, engineFilters(s)]);

export const queryBody = (s: Explorer) => ({
  range: resolveRange(s),
  search: s.q || null,
  filters: engineFilters(s),
});

// ---- Page --------------------------------------------------------------------

export function Logs() {
  const s = useExplorerState();
  const [live, setLive] = useState(false);
  const refreshMs = live ? LIVE_MS : 0;
  const projects = useQuery({ queryKey: ["projects"], queryFn: () => api<Project[]>("/v1/projects") });

  const exportRef = useRef<(() => void) | null>(null);
  // A chip just applied shows the pending spinner until the logs query it triggered settles.
  const [edited, setEdited] = useState<string | null>(null);
  const fetching = useIsFetching({ queryKey: ["logs"] });
  const seen = useRef(false);
  useEffect(() => {
    if (!edited) return;
    if (fetching > 0) seen.current = true;
    else if (seen.current) {
      seen.current = false;
      setEdited(null);
    }
  }, [edited, fetching]);
  // The edit may not change the query at all (no fetch): don't spin forever.
  useEffect(() => {
    if (!edited) return;
    // A fetch already seen clears the spinner when it settles, however slow it is.
    const t = setTimeout(() => seen.current || setEdited(null), 4000);
    return () => clearTimeout(t);
  }, [edited]);
  const changed = (f: Filter, next?: Filter) => {
    // Only a change to the engine-facing question will refetch, so only then show the pending state.
    if (JSON.stringify(toEngine(next ?? f)) !== JSON.stringify(next ? toEngine(f) : null)) {
      seen.current = false;
      setEdited(encodeFilter(next ?? f));
    }
    if (next) s.replaceFilter(f, next);
    else s.addFilter(f);
  };

  const hasFilters = s.filters.length > 0 || s.legacy.length > 0;
  const projectName = projects.data?.find((p) => p.id === s.project)?.name ?? s.project;

  return (
    <div className="flex min-h-full flex-col">
      <header className="flex flex-wrap items-center justify-between gap-3 px-4 pt-[26px] pb-4 sm:px-[30px]">
        <div className="flex flex-col gap-1">
          <h1 className="text-[24px] leading-tight font-semibold tracking-tight">Logs</h1>
          <p className="text-body text-ink-muted">Search, filter and shape events from every project</p>
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            className={`lh-control h-[34px] rounded-md px-3 font-medium ${live ? "border-brand-line bg-brand-tint text-brand" : "bg-canvas"}`}
            aria-pressed={live}
            title={`Refresh the results every ${LIVE_MS / 1000}s`}
            onClick={() => setLive(!live)}
          >
            Live tail
            <span className={`size-[7px] rounded-full ${live ? "animate-pulse bg-[#16A34A]" : "bg-ink-faint"}`} aria-hidden="true" />
          </button>
          <button type="button" className="lh-control h-[34px] rounded-md bg-canvas px-3 font-medium" title="Download the loaded results as CSV" onClick={() => exportRef.current?.()}>
            <Icon name="download" className="size-3.5" />
            Export
          </button>
        </div>
      </header>

      <div className="px-4 sm:px-[30px]">
        <QueryBar q={s.q} range={s.range} from={s.from} to={s.to} onSet={s.set} />
      </div>

      <div className="flex flex-wrap items-center gap-2 px-4 py-4 sm:px-[30px]" role="group" aria-label="Filters">
        {s.project && <FilterChip s={s} locked filter={{ field: "project", op: "equals", value: projectName }} />}
        {[...s.legacy.filter((l) => !s.filters.some((f) => encodeFilter(f) === encodeFilter(l))), ...s.filters].map((f) => (
          <FilterChip key={encodeFilter(f)} s={s} filter={f} pending={edited === encodeFilter(f) && fetching > 0} onChange={(next) => changed(f, next)} onRemove={() => s.removeFilter(f)} />
        ))}
        <AddFilter s={s} onAdd={(f) => changed(f)} />
        {hasFilters && (
          <button type="button" className="rounded px-1 text-label text-ink-muted hover:text-ink" onClick={s.clearFilterChips}>
            Clear all
          </button>
        )}
      </div>

      <LogsTable s={s} refreshMs={refreshMs} hasFilters={hasFilters} exportRef={exportRef} />
      {s.log && <Drawer s={s} />}
    </div>
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
