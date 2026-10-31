import { useIsFetching } from "@tanstack/react-query";
import { useRef, useState } from "react";
import { Icon } from "@/icons";
import { type Filter, OP_LABEL, displayKey, displayValues, isInvalid, isNegated, isOff, opOf, setOff } from "@/lib/attributes";
import { FilterEditor, type Scope } from "./FilterEditor";

const OPPOSITE: Record<string, string> = {
  equals: "not_equals", not_equals: "equals", in: "not_in", not_in: "in", contains: "not_contains", not_contains: "contains",
};
const focusAdd = () => document.getElementById("lh-add-filter")?.focus();
const MAX_TAGS = 2;

/** One applied filter: click to edit, eye to disable, x (or Backspace) to remove, alt-click to negate. */
export function FilterChip({ s, filter, locked = false, pending = false, onChange, onRemove }: {
  s: Scope;
  filter: Filter;
  locked?: boolean;
  /** The logs query for this chip is still loading. */
  pending?: boolean;
  onChange?: (next: Filter) => void;
  onRemove?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const trigger = useRef<HTMLButtonElement>(null);
  const close = (restoreFocus = true) => {
    setOpen(false);
    if (restoreFocus) trigger.current?.focus();
  };
  // Spinner while the editor is still counting/validating values, or the query for a just-applied chip loads.
  const loadingValues = useIsFetching({ queryKey: ["filter-values"] }) > 0;
  const wait = pending || (open && loadingValues);
  const freeText = filter.field === "message" && opOf(filter) === "contains";
  const off = isOff(filter);
  const op = opOf(filter);
  const invalid = !locked && isInvalid(filter);
  const negated = isNegated(op);
  const level = filter.field === "level";
  const vals = displayValues(filter).map((v) => (level ? v.toUpperCase() : v));
  const shown = vals.length > 1 ? vals.slice(0, MAX_TAGS) : vals;
  const extra = vals.length - shown.length;
  const opText = op === "not_exists" ? OP_LABEL.exists : (OP_LABEL[op] ?? op);
  const valText = op === "exists" ? "✓" : op === "not_exists" ? "✗" : null;
  const summary = `${displayKey(filter.field)} ${OP_LABEL[op] ?? op} ${vals.join(", ")}`.trim();

  const tone = open
    ? "border-brand bg-brand/[0.06]"
    : invalid
      ? "border-error-dot bg-error-tint"
      : negated
        ? "border-error-dot/40 bg-error-tint hover:border-error-dot/70"
        : locked
          ? "border-line bg-sunken"
          : "border-line bg-surface hover:border-[#A1A1AA] hover:bg-sunken";
  const remove = () => {
    onRemove?.();
    focusAdd();
  };
  const icon = "size-3 shrink-0";

  return (
    <span className="relative inline-flex">
      <span className={`group inline-flex h-7 items-center gap-1.5 rounded-md border pr-1.5 pl-[9px] text-label transition-colors ${tone} ${off ? "opacity-50" : ""}`}>
        {negated && <Icon name="ban" className={`${icon} text-error-ink`} />}
        {invalid && <Icon name="triangle-alert" className={`${icon} text-error-ink`} />}
        {freeText && !negated && <Icon name="text-search" className={`${icon} text-ink-muted`} />}
        {locked ? (
          <span className="flex items-center gap-1.5" title="Set by the project selector">
            <span className="text-ink-muted">{displayKey(filter.field)}</span>
            <span className="text-ink-muted">{opText}</span>
            <span className="max-w-40 truncate font-semibold">{filter.value}</span>
            <Icon name="lock" className={`${icon} text-ink-muted`} />
          </span>
        ) : (
          <button
            ref={trigger}
            type="button"
            className="flex min-w-0 items-center gap-1.5 rounded-sm focus-visible:outline-2"
            aria-haspopup="dialog"
            aria-expanded={open}
            aria-label={`Edit filter: ${summary}${off ? " (disabled)" : ""}${invalid ? " (invalid)" : ""}`}
            title={invalid ? "This value does not match the operator, so the filter is not applied" : undefined}
            onClick={(e) => {
              if (e.altKey && OPPOSITE[op]) onChange?.({ ...filter, op: (off ? "!" : "") + OPPOSITE[op] });
              else setOpen(!open);
            }}
            onKeyDown={(e) => {
              if (e.key === "Backspace" || e.key === "Delete") {
                e.preventDefault();
                remove();
              }
            }}
          >
            <span className="max-w-40 truncate text-ink-muted">{displayKey(filter.field)}</span>
            <span className={negated ? "font-semibold text-error-ink" : "text-ink-muted"}>{opText}</span>
            {valText ? (
              <span className="font-semibold">{valText}</span>
            ) : vals.length > 1 ? (
              <>
                {shown.map((v) => <span key={v} className="max-w-24 truncate rounded bg-sunken px-1.5 py-px font-semibold">{v}</span>)}
                {extra > 0 && <span className="rounded-[9px] bg-brand/[0.12] px-[5px] py-px text-micro font-semibold text-brand">+{extra}</span>}
              </>
            ) : (
              <span className={`max-w-40 truncate font-semibold ${invalid ? "text-error-ink" : ""}`}>{freeText ? `\u201C${vals[0]}\u201D` : vals[0]}</span>
            )}
          </button>
        )}
        {!locked && wait && <Icon name="loader" className={`${icon} animate-spin text-ink-muted`} />}
        {!locked && !wait && (
          <>
            <button
              type="button"
              className={`rounded p-0.5 text-ink-muted hover:text-ink focus-visible:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100 ${off ? "" : "opacity-0"}`}
              aria-pressed={off}
              title={off ? "Enable filter" : "Disable filter"}
              onClick={() => onChange?.(setOff(filter, !off))}
            >
              <Icon name={off ? "eye-off" : "eye"} className={icon} />
              <span className="sr-only">{off ? "Enable this filter" : "Disable this filter"}</span>
            </button>
            <button type="button" className={`rounded p-0.5 text-ink-muted hover:text-ink ${off ? "hidden group-focus-within:block group-hover:block" : ""}`} onClick={remove}>
              <Icon name="close" className={icon} />
              <span className="sr-only">Remove filter: {summary}</span>
            </button>
          </>
        )}
      </span>
      {open && (
        <FilterEditor
          s={s}
          initial={filter}
          onCancel={close}
          onApply={(f) => {
            close();
            onChange?.(off ? setOff(f, true) : f);
          }}
        />
      )}
    </span>
  );
}

/** "Add filter" button and its popover. */
export function AddFilter({ s, onAdd }: { s: Scope; onAdd: (f: Filter) => void }) {
  const [open, setOpen] = useState(false);
  return (
    <span className="relative inline-flex">
      <button
        id="lh-add-filter"
        type="button"
        className="inline-flex h-7 items-center gap-1.5 rounded-md border border-line bg-canvas px-2.5 text-label font-medium hover:border-line-strong hover:bg-sunken"
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => setOpen(!open)}
      >
        <Icon name="plus" className="size-[13px]" /> Add filter
      </button>
      {open && (
        <FilterEditor
          s={s}
          onCancel={() => {
            setOpen(false);
            document.getElementById("lh-add-filter")?.focus();
          }}
          onApply={(f) => {
            setOpen(false);
            onAdd(f);
            document.getElementById("lh-add-filter")?.focus();
          }}
        />
      )}
    </span>
  );
}
