import { describe, expect, it } from "vitest";
import { bucketSeconds, resolveRange } from "./range";

describe("resolveRange", () => {
  it("turns a preset into a bounded window ending now", () => {
    const { from, to } = resolveRange({ range: "1h" });
    expect(Date.parse(to!) - Date.parse(from!)).toBe(3600e3);
  });
  it("falls back to 24h for an unknown preset", () => {
    const { from, to } = resolveRange({ range: "bogus" });
    expect(Date.parse(to!) - Date.parse(from!)).toBe(24 * 3600e3);
  });
  it("keeps custom bounds and drops invalid ones", () => {
    expect(resolveRange({ range: "custom", from: "2026-01-01T00:00", to: "nope" })).toEqual({ from: new Date("2026-01-01T00:00").toISOString(), to: null });
  });
});

describe("bucketSeconds", () => {
  it("keeps every preset under the engine's 1000-bucket cap", () => {
    for (const [r, ms] of [["15m", 15 * 60e3], ["1h", 3600e3], ["24h", 86400e3], ["7d", 7 * 86400e3]] as const)
      expect(ms / 1000 / bucketSeconds(r)).toBeLessThanOrEqual(1000);
  });
});
