import { useQuery } from "@tanstack/react-query";
import { useEffect, useId, useLayoutEffect, useRef, useState } from "react";
import { api } from "@/api";
import { Icon } from "@/icons";
import { LEVELS } from "@/components/Severity";
import {
  type AttrType, type AttributeKey, type Filter, BUILTIN_FIELDS, LEVEL_OPS, OP_LABEL, attributeField, displayKey, fromList, isBuiltin,
  isInvalid, isList, needsValue, opOf, opsFor, toList,
} from "@/lib/attributes";
import { resolveRange } from "@/lib/range";
import { scopeBase } from "@/lib/scope";

export type Scope = { project: string; range: string; from: string; to: string };
type Entry = { field: string; label: string; type: AttrType; count?: number };

const BADGE: Record<AttrType, string> = { string: "Aa", number: "123", boolean: "T/F" };
const FACET_FIELDS = ["service.name", "environment", "channel"];
const MAX_VALUES = 50;
const fmt = (n: number) => n.toLocaleString("en-US");

const rangeQuery = (s: Scope) => {
  const r = resolveRange(s);
  const qs = new URLSearchParams();
  if (r.from) qs.set("from", r.from);
  if (r.to) qs.set("to", r.to);
  return qs;
};

/**
 * Popover to add a filter (pick a key, then operator and value) or edit an existing one.
 * Enter applies, Escape or a click outside cancels.
 */
export function FilterEditor({ s, initial, onApply, onCancel }: {
  s: Scope;
  initial?: Filter;
  onApply: (f: Filter) => void;
  onCancel: (restoreFocus?: boolean) => void;
}) {
  const id = useId();
  const root = useRef<HTMLDivElement>(null);
  const keyInput = useRef<HTMLInputElement>(null);
  const valueInput = useRef<HTMLInputElement>(null);

  const keysQ = useQuery({
    queryKey: ["attribute-keys", s.project, s.range, s.from, s.to],
    queryFn: () => api<{ keys: AttributeKey[] }>(`${scopeBase(s.project)}/logs/attribute-keys?${rangeQuery(s)}`),
  });
  const entries: Entry[] = [
    ...BUILTIN_FIELDS.map((b) => ({ field: b.key, label: b.key, type: b.type })),
    ...(keysQ.data?.keys ?? []).map((k) => ({ field: attributeField(k.key), label: k.key, type: k.type, count: k.count })),
  ];

  const startOp = initial ? opOf(initial) : "equals";
  const [picked, setPicked] = useState<Entry | null>(
    initial ? { field: initial.field, label: displayKey(initial.field), type: "string" } : null,
  );
  const [op, setOp] = useState(startOp);
  const [values, setValues] = useState<string[]>(initial && isList(startOp) ? toList(initial.value) : []);
  const [draft, setDraft] = useState(initial && !isList(startOp) ? initial.value : "");
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);

  // The key list refines the type once it has loaded (an edited chip only knows its field).
  const entry = picked && (entries.find((e) => e.field === picked.field) ?? picked);
  const type = entry?.type ?? "string";
  const baseOps = entry?.field === "level" ? LEVEL_OPS : opsFor(type);
  const ops = baseOps.includes(op) ? baseOps : [op, ...baseOps];
  const list = isList(op);

  const needle = query.trim().toLowerCase();
  const shownKeys = entries.filter((e) => e.label.toLowerCase().includes(needle));

  const attrKey = entry && !isBuiltin(entry.field) ? entry.label : "";
  const facet = entry && FACET_FIELDS.includes(entry.field) ? entry.field : "";
  const valuesQ = useQuery({
    queryKey: ["filter-values", s.project, s.range, s.from, s.to, entry?.field],
    enabled: !!(attrKey || facet),
    queryFn: async (): Promise<{ value: string; count?: number }[]> => {
      const qs = rangeQuery(s);
      if (facet) return (await api<{ values: string[] }>(`${scopeBase(s.project)}/logs/facets/${facet}?${qs}`)).values.map((value) => ({ value }));
      qs.set("key", attrKey);
      return (await api<{ values: { value: string; count: number }[] }>(`${scopeBase(s.project)}/logs/attribute-values?${qs}`)).values;
    },
  });
  const suggestions = entry?.field === "level" ? LEVELS.map(([value]) => ({ value, count: undefined })) : (valuesQ.data ?? []);
  const exact = suggestions.some((v) => v.value === draft);
  const dn = exact ? "" : draft.trim().toLowerCase();
  const shownValues = suggestions.filter((v) => v.value.toLowerCase().includes(dn)).slice(0, 50);
  const maxCount = Math.max(1, ...shownValues.map((v) => v.count ?? 0));

  const pending = draft.trim();
  const full = values.length >= MAX_VALUES;
  const finalValues = list && pending && !values.includes(pending) && !full ? [...values, pending] : values;
  const canApply = !!entry && (!needsValue(op) || (list ? finalValues.length > 0 : pending !== ""));
  const next: Filter | null = entry && { field: entry.field, op, value: !needsValue(op) ? "" : list ? fromList(finalValues) : draft.trim() };
  const apply = () => next && canApply && onApply(next);

  const pick = (e: Entry) => {
    setPicked(e);
    setOp(e.field === "message" ? "contains" : "equals"); // the free-text "message contains" chip
    setValues([]);
    setDraft("");
  };
  const changeOp = (o: string) => {
    if (isList(o) && !list) {
      setValues(pending ? [pending] : []);
      setDraft("");
    } else if (!isList(o) && list) {
      setDraft(values[0] ?? draft);
      setValues([]);
    }
    setOp(o);
  };
  const toggle = (v: string) =>
    list ? setValues((vs) => (vs.includes(v) ? vs.filter((x) => x !== v) : vs.length < MAX_VALUES ? [...vs, v] : vs)) : setDraft(v);

  // Focus the key search when adding, the value box once a key is chosen.
  useEffect(() => {
    (picked ? valueInput.current : keyInput.current)?.focus();
  }, [picked?.field]); // eslint-disable-line react-hooks/exhaustive-deps

  // Keep the popover on screen: open towards the left when it would overflow the viewport.
  useLayoutEffect(() => {
    const el = root.current;
    if (!el) return;
    if (el.getBoundingClientRect().right > window.innerWidth - 16) el.classList.add("right-0", "left-auto");
    const left = el.getBoundingClientRect().left;
    if (left < 16) el.style.transform = `translateX(${16 - left}px)`; // narrow screens: keep it inside the viewport
  }, []);

  // Outside click cancels; the trigger sits in the same wrapper so it can toggle the popover itself.
  useEffect(() => {
    const down = (e: PointerEvent) => {
      if (!root.current?.parentElement?.contains(e.target as Node)) onCancel();
    };
    document.addEventListener("pointerdown", down);
    return () => document.removeEventListener("pointerdown", down);
  });

  const onKeyInput = (e: React.KeyboardEvent) => {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      setActive((a) => Math.max(0, Math.min(shownKeys.length - 1, a + (e.key === "ArrowDown" ? 1 : -1))));
    } else if (e.key === "Enter" && shownKeys[active]) {
      e.preventDefault();
      e.stopPropagation();
      pick(shownKeys[active]);
    }
  };
  const onValueKey = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter") {
      e.preventDefault();
      apply();
    } else if (list && e.key === "," && pending) {
      e.preventDefault();
      if (!values.includes(pending) && !full) setValues([...values, pending]);
      setDraft("");
    } else if (list && e.key === "Backspace" && !draft) {
      setValues(values.slice(0, -1));
    }
  };

  return (
    <div
      ref={root}
      role="dialog"
      aria-label={initial ? "Edit filter" : "Add filter"}
      className="absolute top-full left-0 z-30 mt-1 w-[380px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-[10px] border border-line bg-surface text-ink shadow-[0_8px_24px_rgb(0_0_0/0.15)]"
      onBlur={(e) => {
        if (e.relatedTarget instanceof Node && !root.current?.parentElement?.contains(e.relatedTarget)) onCancel(false);
      }}
      onKeyDown={(e) => {
        if (e.key === "Escape") {
          e.stopPropagation();
          onCancel();
        } else if (e.key === "Enter" && e.target instanceof HTMLElement && e.target.tagName !== "BUTTON" && e.target.tagName !== "INPUT") apply();
      }}
    >
      {!initial && (
        <>
          <div className="flex items-center gap-2 border-b border-line px-3.5 py-3">
            <Icon name="search" className="size-3.5 shrink-0 text-ink-muted" />
            <input
              ref={keyInput}
              role="combobox"
              aria-expanded="true"
              aria-controls={`${id}-keys`}
              aria-autocomplete="list"
              aria-activedescendant={shownKeys[active] ? `${id}-k${active}` : undefined}
              aria-label="Filter key"
              className="min-w-0 flex-1 bg-transparent text-body outline-none placeholder:text-ink-faint"
              placeholder="Find a field or attribute…"
              value={query}
              onChange={(e) => {
                setQuery(e.target.value);
                setActive(0);
              }}
              onKeyDown={onKeyInput}
            />
            <span className="shrink-0 text-micro text-ink-muted">Attributes</span>
          </div>
          <ul id={`${id}-keys`} role="listbox" aria-label="Fields" className="max-h-56 overflow-auto p-1.5">
            <li role="presentation" className="px-2 py-1 text-[10px] font-semibold tracking-wide text-ink-muted uppercase">Matching attributes</li>
            {shownKeys.length === 0 && <li className="px-2 py-1.5 text-label text-ink-muted">{keysQ.isPending ? "Loading…" : "No matching field"}</li>}
            {shownKeys.map((e, i) => (
              <li
                key={e.field}
                id={`${id}-k${i}`}
                role="option"
                aria-selected={e.field === picked?.field}
                className={`flex h-[30px] cursor-pointer items-center gap-2 rounded-md px-2 ${i === active ? "bg-accent" : ""}`}
                onMouseEnter={() => setActive(i)}
                onMouseDown={(ev) => ev.preventDefault()}
                onClick={() => pick(e)}
              >
                <span className="flex h-[18px] w-[22px] shrink-0 items-center justify-center rounded bg-sunken text-[9px] font-bold text-ink-muted" title={e.type}>{BADGE[e.type]}</span>
                <span className={`min-w-0 flex-1 truncate text-label ${i === active || e.field === picked?.field ? "font-semibold" : ""}`}>{e.label}</span>
                {e.count != null && <span className="tnum text-micro text-ink-muted">{fmt(e.count)}</span>}
              </li>
            ))}
          </ul>
        </>
      )}

      {entry && (
        <div className={`flex flex-col gap-2.5 bg-sunken p-3.5 ${initial ? "" : "border-t border-line"}`}>
          <div className="flex items-center gap-2">
            <span className="min-w-0 truncate text-label font-semibold" title={entry.label}>{entry.label}</span>
            <select
              aria-label="Operator"
              className="h-[26px] rounded-md border border-line bg-surface px-1.5 text-label font-medium"
              value={op}
              onChange={(e) => changeOp(e.target.value)}
            >
              {ops.map((o) => <option key={o} value={o}>{OP_LABEL[o] ?? o}</option>)}
            </select>
          </div>

          {needsValue(op) && (
            <>
              <div className="flex min-h-[34px] flex-wrap items-center gap-1.5 rounded-md border border-line bg-surface px-2.5 py-1 focus-within:border-brand focus-within:ring-1 focus-within:ring-brand">
                {values.map((v) => (
                  <span key={v} className="inline-flex items-center gap-1 rounded bg-sunken py-0.5 pr-1 pl-[7px] text-micro font-semibold">
                    <span className="max-w-32 truncate">{v}</span>
                    <button type="button" className="rounded p-0.5 text-ink-muted hover:text-ink" onClick={() => setValues(values.filter((x) => x !== v))}>
                      <Icon name="close" className="size-2.5" />
                      <span className="sr-only">Remove {v}</span>
                    </button>
                  </span>
                ))}
                <input
                  ref={valueInput}
                  aria-label="Value"
                  className="min-w-24 flex-1 bg-transparent text-label outline-none placeholder:text-ink-faint"
                  placeholder={list ? "Type or pick a value…" : "Type or pick a value…"}
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  onKeyDown={onValueKey}
                />
              </div>
              {next && isInvalid(next) && pending !== "" && (
                <p className="text-micro text-error-ink" role="status">Not a number: this filter will not be applied.</p>
              )}
              {shownValues.length > 0 && (
                <>
                  <p className="text-[10px] font-semibold tracking-wide text-ink-muted uppercase">Suggested values</p>
                  <ul aria-label="Suggested values" className="flex max-h-36 flex-col gap-0.5 overflow-auto">
                    {shownValues.map((v) => {
                      const on = list ? values.includes(v.value) : draft === v.value;
                      return (
                        <li key={v.value}>
                          <button
                            type="button"
                            role={list ? "checkbox" : undefined}
                            aria-checked={list ? on : undefined}
                            aria-pressed={list ? undefined : on}
                            className="flex w-full items-center gap-2 rounded px-0.5 py-1 text-left hover:bg-line/50"
                            onClick={() => toggle(v.value)}
                          >
                            <span className={`flex size-[15px] shrink-0 items-center justify-center rounded border ${on ? "border-brand bg-brand text-brand-ink" : "border-line bg-surface"}`}>
                              {on && <Icon name="check" className="size-2.5" />}
                            </span>
                            <span className="min-w-0 flex-1 truncate text-label">{v.value}</span>
                            {v.count != null && (
                              <>
                                <span className="h-1 w-[70px] shrink-0 rounded-full bg-line" aria-hidden="true">
                                  <span className="block h-full rounded-full bg-[#94A3B8]" style={{ width: `${Math.max(4, (v.count / maxCount) * 100)}%` }} />
                                </span>
                                <span className="tnum shrink-0 text-right text-micro text-ink-muted">{fmt(v.count)}</span>
                              </>
                            )}
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                </>
              )}
            </>
          )}

          <div className="flex items-center gap-2">
            <span className="text-micro text-ink-muted">↵ apply &nbsp; esc cancel</span>
            <span className="flex-1" />
            <button type="button" className="lh-button lh-button-quiet h-7 text-label" onClick={() => onCancel()}>Cancel</button>
            <button type="button" className="lh-button lh-button-primary h-7 text-label" disabled={!canApply} onClick={apply}>Apply filter</button>
          </div>
        </div>
      )}
    </div>
  );
}
