import { describe, expect, it } from "vitest";
import { DEFAULT_LAYOUT, autoFitWidth, clampWidth, moveColumn, parseLayout, reorderColumn, visibleColumns } from "./columns";

describe("columns", () => {
  it("shows defaults then URL columns, deduplicated", () => {
    expect(visibleColumns(["attributes.a", "service.name"], DEFAULT_LAYOUT)).toEqual(["timestamp", "level", "service.name", "message", "attributes.a"]);
  });
  it("honours saved order and hidden, but never hides locked columns", () => {
    const l = { ...DEFAULT_LAYOUT, order: ["message", "timestamp"], hidden: ["service.name", "level"] };
    expect(visibleColumns([], l)).toEqual(["message", "timestamp", "level"]);
  });
  it("moves and reorders within bounds", () => {
    expect(moveColumn(["a", "b", "c"], "a", 1)).toEqual(["b", "a", "c"]);
    expect(moveColumn(["a", "b", "c"], "a", -1)).toEqual(["a", "b", "c"]);
    expect(reorderColumn(["a", "b", "c"], "c", "a")).toEqual(["c", "a", "b"]);
  });
  it("clamps widths to the minimum", () => {
    expect(clampWidth(10)).toBe(60);
    expect(autoFitWidth(100)).toBe(120);
  });
  it("parses garbage as the default layout and sanitizes good input", () => {
    expect(parseLayout("{nope")).toEqual(DEFAULT_LAYOUT);
    expect(parseLayout(JSON.stringify({ hidden: ["message", "x"], widths: { x: 5, y: "a" }, density: "compact", wrap: true }))).toEqual({
      order: [], hidden: ["x"], widths: { x: 60 }, density: "compact", wrap: true,
    });
  });
});
