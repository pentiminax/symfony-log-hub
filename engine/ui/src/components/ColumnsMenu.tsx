import { useQuery } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import { api } from "@/api";
import { Icon } from "@/icons";
import { type AttributeKey, attributeField } from "@/lib/attributes";
import { LOCKED } from "@/lib/columns";
import { resolveRange } from "@/lib/range";
import { scopeBase } from "@/lib/scope";

type Scope = { project: string; range: string; from: string; to: string };
const OPTIONAL = ["channel", "environment", "trace.id", "span.id"];
export const columnLabel = (c: string) => c.replace(/^attributes\./, "");

export function ColumnsMenu({ scope, visible, hidden, onToggle, onMove, onReorder, onReset, onClose }: {
  scope: Scope;
  visible: string[];
  /** Default columns currently switched off. */
  hidden: string[];
  onToggle: (id: string, on: boolean) => void;
  onMove: (id: string, delta: number) => void;
  onReorder: (id: string, target: string) => void;
  onReset: () => void;
  onClose: () => void;
}) {
  const [q, setQ] = useState("");
  const [drag, setDrag] = useState<string | null>(null);
  const ref = useRef<HTMLDivElement>(null);
  // The trigger that had focus when opened (header "+" or toolbar button).
  const triggerRef = useRef<HTMLElement | null>(document.activeElement as HTMLElement | null);

  const qs = new URLSearchParams();
  const r = resolveRange(scope);
  if (r.from) qs.set("from", r.from);
  if (r.to) qs.set("to", r.to);
  const keys = useQuery({
    queryKey: ["attribute-keys", scope.project, scope.range, scope.from, scope.to],
    queryFn: () => api<{ keys: AttributeKey[] }>(`${scopeBase(scope.project)}/logs/attribute-keys?${qs}`),
  });

  useEffect(() => {
    const down = (e: PointerEvent) => !ref.current?.contains(e.target as Node) && !(e.target as Element).closest?.("[data-columns-trigger]") && onClose();
    const key = (e: KeyboardEvent) => {
      if (e.key !== "Escape") return;
      onClose();
      (triggerRef.current ?? document.querySelector<HTMLElement>("[data-columns-trigger]"))?.focus();
    };
    document.addEventListener("pointerdown", down);
    document.addEventListener("keydown", key);
    return () => {
      document.removeEventListener("pointerdown", down);
      document.removeEventListener("keydown", key);
    };
  }, [onClose]);
  useEffect(() => ref.current?.querySelector<HTMLInputElement>("input")?.focus(), []);

  const needle = q.trim().toLowerCase();
  const match = (c: string) => !needle || c.toLowerCase().includes(needle);
  const shown = [...visible, ...hidden].filter(match);
  const available = [...OPTIONAL, ...(keys.data?.keys ?? []).map((k) => attributeField(k.key))]
    .filter((c) => !visible.includes(c) && match(c));

  const box = (on: boolean) => (
    <span className={`flex size-[15px] shrink-0 items-center justify-center rounded border peer-focus-visible:ring-2 peer-focus-visible:ring-brand ${on ? "border-brand bg-brand text-white" : "border-line bg-surface"}`}>
      {on && <Icon name="check" className="size-2.5" />}
    </span>
  );
  const section = "px-2 pt-0.5 pb-1 text-[10px] font-semibold tracking-wide text-ink-muted uppercase";

  return (
    <div ref={ref} role="dialog" aria-label="Columns" onBlur={(e) => e.relatedTarget instanceof Element && !ref.current?.contains(e.relatedTarget) && !e.relatedTarget.closest("[data-columns-trigger]") && onClose()} className="absolute top-full left-0 z-30 mt-1 flex max-h-[min(560px,80vh)] w-[300px] flex-col overflow-hidden rounded-[10px] border border-line bg-surface shadow-[0_8px_24px_rgb(0_0_0/0.15)]">
      <div className="flex items-center gap-2 border-b border-line px-3 py-2.5">
        <Icon name="search" className="size-3.5 shrink-0 text-ink-muted" />
        <input type="search" className="min-w-0 flex-1 bg-transparent text-body outline-none placeholder:text-ink-muted [&::-webkit-search-cancel-button]:appearance-none" placeholder="Find an attribute…" aria-label="Search columns" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="px-1.5 pt-2 pb-1">
          <p className={section}>Visible · drag to reorder</p>
          <ul>
            {shown.map((c) => {
              const on = visible.includes(c);
              const locked = LOCKED.has(c);
              const i = visible.indexOf(c);
              return (
                <li
                  key={c}
                  draggable={on && !locked}
                  onDragStart={(e) => {
                    e.dataTransfer.setData("text/plain", c); // Firefox needs data to start a drag
                    e.dataTransfer.effectAllowed = "move";
                    setDrag(c);
                  }}
                  onDragEnd={() => setDrag(null)}
                  onDragOver={(e) => on && drag && e.preventDefault()}
                  onDrop={() => drag && on && onReorder(drag, c)}
                  className={`group flex h-[30px] items-center gap-2 rounded-md px-2 ${drag === c ? "bg-accent shadow-[0_8px_24px_rgb(0_0_0/0.15)]" : "hover:bg-sunken"}`}
                >
                  <Icon name="grip-vertical" className={`size-[13px] shrink-0 ${on ? (locked ? "text-[#D4D4D8]" : "cursor-grab text-ink-muted") : "text-[#D4D4D8]"}`} />
                  <label className="flex min-w-0 flex-1 items-center gap-2 text-label">
                    <input type="checkbox" className="peer sr-only" checked={on} disabled={locked} onChange={(e) => onToggle(c, e.target.checked)} />
                    {box(on)}
                    <span className="truncate" title={c}>{columnLabel(c)}</span>
                  </label>
                  {locked && <Icon name="lock" className="size-3 shrink-0 text-[#A1A1AA]" />}
                  {/* Keyboard reorder: visually hidden until focused. */}
                  {on && (
                    <>
                      <button type="button" className="lh-row-action sr-only focus-visible:not-sr-only" disabled={i <= 0} onClick={() => onMove(c, -1)}>
                        <Icon name="chevron-up" className="size-3.5" /><span className="sr-only">Move {columnLabel(c)} up</span>
                      </button>
                      <button type="button" className="lh-row-action sr-only focus-visible:not-sr-only" disabled={i >= visible.length - 1} onClick={() => onMove(c, 1)}>
                        <Icon name="chevron-down" className="size-3.5" /><span className="sr-only">Move {columnLabel(c)} down</span>
                      </button>
                    </>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
        {available.length > 0 && (
          <>
            <div className="h-px bg-line" />
            <div className="px-1.5 pt-2 pb-1.5">
              <p className={section}>Discovered attributes</p>
              <ul>
                {available.map((c) => (
                  <li key={c} className="flex h-[30px] items-center gap-2 rounded-md px-2 hover:bg-sunken">
                    <Icon name="grip-vertical" className="size-[13px] shrink-0 text-ink-muted" />
                    <label className="flex min-w-0 flex-1 items-center gap-2 text-label">
                      <input type="checkbox" className="peer sr-only" checked={false} onChange={() => onToggle(c, true)} />
                      {box(false)}
                      <span className="truncate" title={c}>{columnLabel(c)}</span>
                    </label>
                  </li>
                ))}
              </ul>
            </div>
          </>
        )}
        {shown.length === 0 && available.length === 0 && <p className="px-3 py-4 text-center text-body text-ink-muted">No matching columns.</p>}
      </div>
      <button type="button" className="flex items-center gap-1.5 border-t border-line bg-sunken px-3.5 py-[9px] text-left text-label font-medium hover:bg-line/50" onClick={onReset}>
        <Icon name="rotate-ccw" className="size-3 text-ink-muted" />
        Reset to default
      </button>
    </div>
  );
}
