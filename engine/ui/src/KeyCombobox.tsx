import { useId, useState } from "react";
import type { AttributeKey } from "@/lib/attributes";

/** Searchable key picker: type to filter, arrows + Enter to choose, Escape to close. */
export function KeyCombobox({ keys, value, onChange }: {
  keys: AttributeKey[];
  value: string;
  onChange: (key: string) => void;
}) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);
  const needle = query.trim().toLowerCase();
  const shown = keys.filter((k) => k.key.toLowerCase().includes(needle));

  const choose = (key: string) => {
    onChange(key);
    setQuery("");
    setOpen(false);
  };
  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      setOpen(true);
      const step = e.key === "ArrowDown" ? 1 : -1;
      setActive((a) => Math.max(0, Math.min(shown.length - 1, a + step)));
    } else if (e.key === "Enter" && open && shown[active]) {
      e.preventDefault();
      choose(shown[active].key);
    } else if (e.key === "Escape") {
      setOpen(false);
    }
  };

  return (
    <div className="relative">
      <input
        role="combobox"
        aria-expanded={open}
        aria-controls={`${id}-list`}
        aria-autocomplete="list"
        aria-activedescendant={open && shown[active] ? `${id}-${active}` : undefined}
        aria-label="Custom attribute"
        className={`lh-control w-56 font-mono ${value ? "border-brand-line bg-brand-tint" : ""}`}
        placeholder={value || "Attributes…"}
        value={query}
        onChange={(e) => {
          setQuery(e.target.value);
          setActive(0);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onKeyDown={onKeyDown}
      />
      {open && (
        <ul
          id={`${id}-list`}
          role="listbox"
          className="absolute z-20 mt-1 max-h-72 w-72 overflow-auto rounded-md border border-line bg-canvas py-1 shadow-lg"
        >
          {shown.length === 0 && <li className="px-3 py-1.5 text-label text-ink-muted">No matching attribute</li>}
          {shown.map((k, i) => (
            <li
              key={k.key}
              id={`${id}-${i}`}
              role="option"
              aria-selected={i === active}
              className={`flex cursor-pointer justify-between gap-3 px-3 py-1.5 font-mono text-micro ${i === active ? "bg-brand-tint" : ""}`}
              onMouseDown={(e) => {
                e.preventDefault(); // keep focus so blur doesn't close before the click lands
                choose(k.key);
              }}
              onMouseEnter={() => setActive(i)}
            >
              <span className="truncate">{k.key}</span>
              <span className="text-ink-muted">{k.count}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
