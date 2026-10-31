import { useEffect, useRef, useState } from "react";
import { Icon } from "@/icons";
import { RANGES } from "@/lib/range";

/**
 * Free-text search + time range + Search button. Typing updates the URL `q` after 400ms
 * (Search or Enter flushes it); Cmd/Ctrl+K focuses the box from anywhere.
 */
export function QueryBar({ q, range, from, to, onSet }: {
  q: string;
  range: string;
  from: string;
  to: string;
  onSet: (key: string, value: string, replace?: boolean) => void;
}) {
  const [input, setInput] = useState(q);
  const ref = useRef<HTMLInputElement>(null);

  // External changes (Reset, back/forward) flow back in.
  useEffect(() => setInput(q), [q]);
  useEffect(() => {
    const v = input.trim() === "" ? "" : input;
    // Compare the normalized value, or a whitespace-only box re-fires this every 400ms.
    if (v === q) return;
    const t = setTimeout(() => onSet("q", v, true), 400);
    return () => clearTimeout(t);
  });
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        ref.current?.focus();
        ref.current?.select();
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    onSet("q", input.trim() === "" ? "" : input);
  };

  return (
    <form
      role="search"
      onSubmit={submit}
      className="group flex min-h-[46px] flex-wrap items-center gap-x-2.5 gap-y-1 rounded-[10px] border-[1.5px] border-line-strong bg-surface py-1 pr-2 pl-3.5 transition-shadow focus-within:border-brand focus-within:shadow-[0_0_8px_rgb(37_99_235/0.15)]"
    >
      <Icon name="search" className="size-4 shrink-0 text-ink-faint group-focus-within:text-brand" />
      <input
        ref={ref}
        type="search"
        className="h-8 min-w-40 flex-1 bg-transparent text-md outline-none placeholder:text-ink-faint [&::-webkit-search-cancel-button]:appearance-none"
        value={input}
        onChange={(e) => setInput(e.target.value)}
        placeholder="Search messages…"
        aria-label="Search log messages"
        aria-keyshortcuts="Control+K Meta+K"
      />
      <kbd className="hidden rounded bg-sunken px-1.5 py-0.5 font-sans text-micro font-medium text-ink-muted sm:block" aria-hidden="true">⌘ K</kbd>
      <span className="hidden h-[22px] w-px bg-line sm:block" aria-hidden="true" />
      <label className="relative flex items-center gap-1.5 px-1.5 text-body font-medium">
        <span className="sr-only">Time range</span>
        <Icon name="clock" className="pointer-events-none size-3.5" />
        <select
          className="cursor-pointer appearance-none bg-transparent pr-5 outline-none"
          value={range}
          onChange={(e) => onSet("range", e.target.value)}
        >
          {RANGES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <Icon name="chevron-down" className="pointer-events-none absolute right-1.5 size-3.5 text-ink-muted" />
      </label>
      {range === "custom" && (
        <>
          <input type="datetime-local" className="lh-control h-7" aria-label="From" value={from} onChange={(e) => onSet("from", e.target.value)} />
          <input type="datetime-local" className="lh-control h-7" aria-label="To" value={to} onChange={(e) => onSet("to", e.target.value)} />
        </>
      )}
      <button type="submit" className="lh-button lh-button-primary h-8 rounded-[7px] px-4">Search</button>
    </form>
  );
}
