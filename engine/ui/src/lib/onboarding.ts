export type Stack = "symfony" | "standalone";
export type Snippet = { title: string; file: string; code: string };

/** `Billing API` → `billing-api`, the service.name the snippets ship. */
export const serviceName = (project: string) =>
  project
    .normalize("NFKD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "") || "my-app";

export function snippets(stack: Stack, { url, key, project, now }: { url: string; key: string; project: string; now: Date }): Snippet[] {
  const service = serviceName(project);
  if (stack === "standalone") {
    const body = JSON.stringify(
      { records: [{ timestamp: now.toISOString(), severity: { text: "INFO", number: 9 }, message: `Hello from ${project}`, resource: { "service.name": service } }] },
      null,
      2,
    );
    return [
      { title: "Export the connection", file: "Shell", code: `export LOGHUB_URL=${url}\nexport LOGHUB_API_KEY=${key}` },
      {
        title: "Send a log",
        file: "POST /api/v1/logs",
        code: `curl -X POST "$LOGHUB_URL/api/v1/logs" \\\n  -H "Authorization: Bearer $LOGHUB_API_KEY" \\\n  -H "Content-Type: application/json" \\\n  -d '${body}'`,
      },
    ];
  }
  return [
    { title: "Install the bundle", file: "Terminal", code: "composer require loghub/symfony-bundle" },
    { title: "Add the connection to .env.local", file: ".env.local", code: `LOGHUB_URL=${url}\nLOGHUB_API_KEY=${key}` },
    {
      title: "Configure the bundle",
      file: "config/packages/loghub.yaml",
      code: `loghub:\n    url: '%env(LOGHUB_URL)%'\n    api_key: '%env(LOGHUB_API_KEY)%'\n    service:\n        name: ${service}`,
    },
    {
      title: "Register the Monolog handler",
      file: "config/packages/monolog.yaml",
      code: "monolog:\n    handlers:\n        loghub:\n            type: service\n            id: loghub.monolog_handler",
    },
  ];
}
