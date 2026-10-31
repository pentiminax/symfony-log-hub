import { describe, expect, it } from "vitest";
import { csvField, toCsv } from "./csv";

describe("csv", () => {
  it("quotes commas, quotes and newlines", () => {
    expect(csvField('a,"b"\nc')).toBe('"a,""b""\nc"');
    expect(csvField("plain")).toBe("plain");
  });
  it("neutralises formulas", () => {
    expect(csvField("=1+1")).toBe("'=1+1");
    expect(csvField("-12.5")).toBe("-12.5");
    expect(csvField("-cmd")).toBe("'-cmd");
  });
  it("joins rows with CRLF", () => {
    expect(toCsv([["a", "b"], ["1", "x,y"]])).toBe('a,b\r\n1,"x,y"\r\n');
  });
});
