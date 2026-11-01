import { describe, expect, it } from "vitest";
import { serviceName, snippets } from "./onboarding";

const ctx = { url: "http://logs.local:8080", key: "lh_abc", project: "Billing API", now: new Date("2026-11-01T10:00:00Z") };

describe("serviceName", () => {
  it("slugifies the project name", () => {
    expect(serviceName("Billing API")).toBe("billing-api");
    expect(serviceName("  Café / Prod ")).toBe("cafe-prod");
    expect(serviceName("***")).toBe("my-app");
  });
});

describe("snippets", () => {
  it("fills the Symfony snippets with the real URL, key and service", () => {
    const code = snippets("symfony", ctx).map((s) => s.code).join("\n");
    expect(code).toContain("LOGHUB_URL=http://logs.local:8080");
    expect(code).toContain("LOGHUB_API_KEY=lh_abc");
    expect(code).toContain("name: billing-api");
    expect(code).toContain("id: loghub.monolog_handler");
  });

  it("sends a payload the ingestion endpoint accepts", () => {
    const [, send] = snippets("standalone", ctx);
    const payload = JSON.parse(send.code.slice(send.code.indexOf("'") + 1, send.code.lastIndexOf("'")));
    expect(payload.records[0]).toEqual({
      timestamp: "2026-11-01T10:00:00.000Z",
      severity: { text: "INFO", number: 9 },
      message: "Hello from Billing API",
      resource: { "service.name": "billing-api" },
    });
  });
});
