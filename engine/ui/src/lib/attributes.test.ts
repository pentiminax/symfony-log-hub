import { describe, expect, it } from "vitest";
import { attributeField, decodeFilter, encodeFilter, opsFor } from "./attributes";

describe("attribute filters", () => {
  it("round-trips a filter whose value contains the separator", () => {
    const f = { field: attributeField("user.plan"), op: "equals", value: "a|b" };
    expect(decodeFilter(encodeFilter(f))).toEqual(f);
  });
  it("rejects a malformed filter", () => expect(decodeFilter("attributes.x")).toBeNull());
  it("offers numeric operators only for number keys", () => {
    expect(opsFor("number")).toContain("greater_than");
    expect(opsFor("string")).not.toContain("greater_than");
  });
});
