import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { api } from "./api";
import { type AttributeKey, attributeField, opsFor } from "@/lib/attributes";
import { resolveRange } from "@/lib/range";
import { scopeBase } from "@/lib/scope";

type Scope = { project: string; range: string; from: string; to: string };

/** Pick a custom attribute seen in recent logs, then filter on it or show it as a column. */
export function AttributePicker({ s, onFilter, onColumn }: {
  s: Scope;
  onFilter: (f: { field: string; op: string; value: string }) => void;
  onColumn: (field: string) => void;
}) {
  const [key, setKey] = useState("");
  const [op, setOp] = useState("equals");
  const [value, setValue] = useState("");
  const qs = new URLSearchParams();
  const r = resolveRange(s);
  if (r.from) qs.set("from", r.from);
  if (r.to) qs.set("to", r.to);
  const keys = useQuery({
    queryKey: ["attribute-keys", s.project, s.range, s.from, s.to],
    queryFn: () => api<{ keys: AttributeKey[] }>(`${scopeBase(s.project)}/logs/attribute-keys?${qs}`),
  });
  const values = useQuery({
    queryKey: ["attribute-values", s.project, s.range, s.from, s.to, key],
    enabled: !!key,
    queryFn: () => {
      const q = new URLSearchParams(qs);
      q.set("key", key);
      return api<{ values: { value: string; count: number }[] }>(`${scopeBase(s.project)}/logs/attribute-values?${q}`);
    },
  });
  const list = keys.data?.keys ?? [];
  const type = list.find((k) => k.key === key)?.type ?? "string";
  const reset = () => {
    setKey("");
    setOp("equals");
    setValue("");
  };
  if (list.length === 0) return null;

  return (
    <>
      <label className="sr-only" htmlFor="lh-attr">Custom attribute</label>
      <select
        id="lh-attr"
        className={`lh-control pr-1.5 ${key ? "border-brand-line bg-brand-tint" : ""}`}
        value={key}
        onChange={(e) => {
          setKey(e.target.value);
          setOp("equals");
          setValue("");
        }}
      >
        <option value="">Attributes…</option>
        {list.map((k) => <option key={k.key} value={k.key}>{k.key} ({k.count})</option>)}
      </select>
      {key && (
        <>
          <label className="sr-only" htmlFor="lh-attr-op">Operator</label>
          <select id="lh-attr-op" className="lh-control pr-1.5" value={op} onChange={(e) => setOp(e.target.value)}>
            {opsFor(type).map((o) => <option key={o} value={o}>{o.replace(/_/g, " ")}</option>)}
          </select>
          <input
            className="lh-control w-36 font-mono"
            list="lh-attr-values"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder="value"
            aria-label="Attribute value"
          />
          <datalist id="lh-attr-values">
            {(values.data?.values ?? []).map((v) => <option key={v.value} value={v.value} />)}
          </datalist>
          <button
            type="button"
            className="lh-button"
            disabled={!value}
            onClick={() => {
              onFilter({ field: attributeField(key), op, value });
              reset();
            }}
          >
            Filter
          </button>
          <button
            type="button"
            className="lh-button"
            onClick={() => {
              onColumn(attributeField(key));
              reset();
            }}
          >
            Column
          </button>
        </>
      )}
    </>
  );
}
