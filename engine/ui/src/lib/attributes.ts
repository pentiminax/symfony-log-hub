import { LEVELS } from "@/components/Severity";

/** `value` is always a string; for `in`/`not_in` it is a JSON array string. */
export type Filter = { field: string; op: string; value: string };
export type AttrType = "string" | "number" | "boolean";
export type AttributeKey = { key: string; count: number; type: AttrType };

/** A disabled filter stays in the URL with a `!` prefix on its operator and is not sent to the engine. */
const OFF = "!";
export const isOff = (f: Filter) => f.op.startsWith(OFF);
export const opOf = (f: Filter) => (isOff(f) ? f.op.slice(1) : f.op);
export const setOff = (f: Filter, off: boolean): Filter => ({ ...f, op: (off ? OFF : "") + opOf(f) });

/** URL form of a filter (`f=field|op|value`); the value may itself contain `|`. */
export const encodeFilter = (f: Filter) => `${f.field}|${f.op}|${f.value}`;
export const decodeFilter = (raw: string): Filter | null => {
  const [field, op, ...rest] = raw.split("|");
  return field && op ? { field, op, value: rest.join("|") } : null;
};

export const attributeField = (key: string) => `attributes.${key}`;

/** Built-in fields offered next to the discovered attributes. */
export const BUILTIN_FIELDS: { key: string; type: AttrType }[] = [
  { key: "level", type: "string" },
  { key: "service.name", type: "string" },
  { key: "environment", type: "string" },
  { key: "channel", type: "string" },
  { key: "message", type: "string" },
  { key: "trace.id", type: "string" },
];
export const isBuiltin = (field: string) => BUILTIN_FIELDS.some((b) => b.key === field);
/** Key shown to the user and typed into the picker: custom attributes lose their `attributes.` prefix. */
export const displayKey = (field: string) => (field.startsWith("attributes.") ? field.slice(11) : field);

export const OP_LABEL: Record<string, string> = {
  equals: "is",
  not_equals: "is not",
  in: "is one of",
  not_in: "is not one of",
  contains: "contains",
  not_contains: "does not contain",
  greater_than: ">",
  greater_than_or_equal: "≥",
  less_than: "<",
  less_than_or_equal: "≤",
  exists: "exists",
  not_exists: "does not exist",
};
const COMPARISON = ["greater_than", "greater_than_or_equal", "less_than", "less_than_or_equal"];
const NEGATED = ["not_equals", "not_in", "not_contains"];
export const isComparison = (op: string) => COMPARISON.includes(op);
export const isNegated = (op: string) => NEGATED.includes(op);
export const isList = (op: string) => op === "in" || op === "not_in";
export const needsValue = (op: string) => op !== "exists" && op !== "not_exists";

/** Numeric comparisons only make sense on keys that are numbers in the sampled rows. */
export const opsFor = (type: AttrType): string[] =>
  type === "number"
    ? ["equals", "not_equals", "in", "not_in", ...COMPARISON, "exists", "not_exists"]
    : type === "boolean"
      ? ["equals", "not_equals", "exists", "not_exists"]
      : ["equals", "not_equals", "in", "not_in", "contains", "not_contains", "exists", "not_exists"];

export const toList = (value: string): string[] => {
  try {
    const v: unknown = JSON.parse(value);
    if (Array.isArray(v)) return v.map(String);
  } catch {
    /* a plain value */
  }
  return value ? [value] : [];
};
export const fromList = (values: string[]) => JSON.stringify(values);

/** Values to render in a chip: the list items, a single value, or none for exists. */
export const displayValues = (f: Filter) => {
  const op = opOf(f);
  return !needsValue(op) ? [] : isList(op) ? toList(f.value) : [f.value];
};

/** Can this filter be sent? A comparison needs a number, other operators need a value. */
export const isInvalid = (f: Filter) => {
  const op = opOf(f);
  if (!needsValue(op)) return false;
  if (isList(op)) return toList(f.value).length === 0;
  if (isComparison(op)) return f.value.trim() === "" || !Number.isFinite(Number(f.value));
  return f.value === "";
};
export const isActive = (f: Filter) => !isOff(f) && !isInvalid(f);

type EngineFilter = { field: string; operator: string; value?: unknown };

/** Level operators the UI offers; severity numbers cannot express contains or comparisons. */
export const LEVEL_OPS = ["equals", "not_equals", "in", "not_in", "exists", "not_exists"];
const bandNumbers = ([lo, hi]: readonly [number, number]) => Array.from({ length: hi - lo + 1 }, (_, i) => lo + i);

/** The engine form of a chip, or null when it is disabled or invalid. `level` is a UI-only field. */
export function toEngine(f: Filter): EngineFilter | null {
  if (!isActive(f)) return null;
  const op = opOf(f);
  if (f.field === "level") return levelToEngine(f, op);
  if (!needsValue(op)) return { field: f.field, operator: op };
  if (isList(op)) return { field: f.field, operator: op, value: toList(f.value) };
  return { field: f.field, operator: op, value: isComparison(op) ? Number(f.value) : f.value };
}

// Monolog stores WARNING/CRITICAL/..., so match on severity numbers rather than severity.text.
function levelToEngine(f: Filter, op: string): EngineFilter | null {
  if (!needsValue(op)) return { field: "severity", operator: op };
  if (!LEVEL_OPS.includes(op)) return null;
  const names = isList(op) ? toList(f.value) : [f.value];
  const bands = names.flatMap((n) => LEVELS.filter((l) => l[0] === n.toLowerCase()).map((l) => l[1]));
  if (bands.length === 0) return null;
  if (op === "equals") return { field: "severity", operator: "between", value: bands[0] };
  return { field: "severity", operator: op === "not_equals" ? "not_in" : op, value: bands.flatMap(bandNumbers) };
}
