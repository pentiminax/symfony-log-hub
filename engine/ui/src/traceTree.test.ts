import { expect, test } from "vitest";
import { type Span, buildTree, toUs } from "./traceTree";

const sp = (id: string, parent: string | null): Span => ({
  trace_id: "t", span_id: id, parent_span_id: parent, name: id, kind: "internal",
  start_time: "2026-01-01T00:00:00Z", end_time: "2026-01-01T00:00:00Z", duration_ns: 0,
  status: { code: "unset", message: null }, service_name: "s", environment: null,
  scope: { name: "s", version: null }, resource: {}, attributes: {}, events: [],
});
const shape = (spans: Span[]) => buildTree(spans).map((r) => `${r.span.span_id}:${r.depth}`);

test("nests children depth-first", () => {
  expect(shape([sp("a", null), sp("b", "a"), sp("c", "b"), sp("d", "a")])).toEqual(["a:0", "b:1", "c:2", "d:1"]);
});
test("orphans attach at root, self-parent too", () => {
  expect(shape([sp("a", "gone"), sp("b", "b")])).toEqual(["a:0", "b:0"]);
});
test("cycles are kept, not lost or looped", () => {
  expect(shape([sp("a", "b"), sp("b", "a")])).toEqual(["a:0", "b:1"]);
});
test("toUs keeps microseconds", () => {
  expect(toUs("2026-01-01T00:00:00.123456Z") - toUs("2026-01-01T00:00:00.123Z")).toBe(456);
});
test("10000-deep and 10000-wide traces build without overflow", () => {
  const deep = [sp("0", null)];
  const wide = [sp("r", null)];
  for (let i = 1; i < 10000; i++) {
    deep.push(sp(String(i), String(i - 1)));
    wide.push(sp(String(i), "r"));
  }
  expect(buildTree(deep).at(-1)!.depth).toBe(9999);
  expect(buildTree(wide).map((r) => r.depth).filter((d) => d === 1)).toHaveLength(9999);
});
