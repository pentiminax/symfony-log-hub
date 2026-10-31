import { describe, expect, it } from "vitest";
import {
  attributeField, decodeFilter, displayValues, encodeFilter, fromList, isActive, isInvalid, opsFor, setOff, toEngine,
} from "./attributes";

const f = (field: string, op: string, value = "") => ({ field, op, value });

describe("attribute filters", () => {
  it("round-trips a filter whose value contains the separator", () => {
    const x = f(attributeField("user.plan"), "equals", "a|b");
    expect(decodeFilter(encodeFilter(x))).toEqual(x);
  });
  it("rejects a malformed filter", () => expect(decodeFilter("attributes.x")).toBeNull());
  it("offers numeric operators only for number keys", () => {
    expect(opsFor("number")).toContain("greater_than");
    expect(opsFor("string")).not.toContain("greater_than");
    expect(opsFor("string")).toContain("in");
  });
});

describe("disabled filters", () => {
  it("round-trips the ! prefix and stays decodable from old URLs", () => {
    const off = setOff(f("channel", "equals", "app"), true);
    expect(off.op).toBe("!equals");
    expect(decodeFilter(encodeFilter(off))).toEqual(off);
    expect(setOff(off, false).op).toBe("equals");
    expect(decodeFilter("channel|equals|app")?.op).toBe("equals");
  });
  it("is not sent to the engine", () => {
    expect(toEngine(setOff(f("channel", "equals", "app"), true))).toBeNull();
    expect(isActive(setOff(f("channel", "equals", "app"), true))).toBe(false);
  });
});

describe("engine conversion", () => {
  it("sends in/not_in values as a JSON array", () => {
    expect(toEngine(f("service.name", "in", fromList(["a", "b"])))).toEqual({ field: "service.name", operator: "in", value: ["a", "b"] });
    expect(displayValues(f("x", "not_in", fromList(["a"])))).toEqual(["a"]);
  });
  it("sends no value for exists", () => {
    expect(toEngine(f("attributes.x", "not_exists"))).toEqual({ field: "attributes.x", operator: "not_exists" });
  });
  it("sends numbers for comparisons and skips unparsable ones", () => {
    expect(toEngine(f("attributes.n", "greater_than_or_equal", "500"))).toEqual({ field: "attributes.n", operator: "greater_than_or_equal", value: 500 });
    expect(isInvalid(f("attributes.n", "less_than", "abc"))).toBe(true);
    expect(toEngine(f("attributes.n", "less_than", "abc"))).toBeNull();
  });
  it("maps the level pseudo-field to severity", () => {
    expect(toEngine(f("level", "equals", "error"))).toEqual({ field: "severity", operator: "between", value: [17, 20] });
    expect(toEngine(f("level", "in", fromList(["error", "warn"])))).toEqual({ field: "severity", operator: "in", value: [17, 18, 19, 20, 13, 14, 15, 16] });
  });
  it("maps every level operator to severity numbers", () => {
    expect(toEngine(f("level", "not_equals", "warn"))).toEqual({ field: "severity", operator: "not_in", value: [13, 14, 15, 16] });
    expect(toEngine(f("level", "not_in", fromList(["fatal"])))).toEqual({ field: "severity", operator: "not_in", value: [21, 22, 23, 24] });
    expect(toEngine(f("level", "exists"))).toEqual({ field: "severity", operator: "exists" });
    expect(toEngine(f("level", "not_exists"))).toEqual({ field: "severity", operator: "not_exists" });
    expect(toEngine(f("level", "contains", "err"))).toBeNull();
    const all = toEngine(f("level", "not_in", fromList(["trace", "debug", "info", "warn", "error", "fatal"])));
    expect((all?.value as number[]).length).toBeLessThanOrEqual(50);
  });
  it("sends nothing for disabled or invalid chips, so the query identity is unaffected by them", () => {
    const chips = [f("level", "equals", "error"), setOff(f("attributes.x", "equals", "a"), true), f("attributes.n", "less_than", "abc")];
    expect(chips.flatMap((c) => toEngine(c) ?? [])).toEqual([toEngine(chips[0])]);
  });
});
