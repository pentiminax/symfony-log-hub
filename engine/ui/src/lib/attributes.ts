export type Filter = { field: string; op: string; value: string };
export type AttributeKey = { key: string; count: number; type: "string" | "number" | "boolean" };

/** URL form of a filter (`f=field|op|value`); the value may itself contain `|`. */
export const encodeFilter = (f: Filter) => `${f.field}|${f.op}|${f.value}`;
export const decodeFilter = (raw: string): Filter | null => {
  const [field, op, ...rest] = raw.split("|");
  return field && op ? { field, op, value: rest.join("|") } : null;
};

export const attributeField = (key: string) => `attributes.${key}`;

/** Numeric comparisons only make sense on keys that are numbers in the sampled rows. */
export const opsFor = (type: AttributeKey["type"]): string[] =>
  type === "number"
    ? ["equals", "not_equals", "greater_than", "less_than"]
    : ["equals", "not_equals", "contains"];
