# loghub-engine

Standalone Rust service that owns LogHub's log storage, ingestion and search on its **own
PostgreSQL database**. Projects and ingestion API keys live here; the master key protects
the admin/search API. Migrations are embedded (`sqlx::migrate!`) and run at startup.

One listener (`LISTEN_ADDR`, default `0.0.0.0:8080`) serves everything, with auth per route:

| Routes | Auth |
|---|---|
| `POST /api/v1/logs`, `POST /v1/traces` (OTLP) | project key `Bearer lh_...` |
| `/v1/projects/...` (admin, search) | `Bearer <MASTER_KEY>` |
| `GET /health` | none |
| everything else (`GET`) | none: the web UI (SPA, falls back to `index.html`) |

Unknown paths under `/v1`, `/api` and `/health` return a JSON `404`, never the UI.

> **Security.** The admin API and the UI share the public port. Use a long random `MASTER_KEY`
> (>= 32 random chars) and put TLS in front (reverse proxy); the master key and project keys travel as
> `Bearer` tokens. Restrict `/v1/projects` and `/` at the proxy if the instance is internet-facing.

## Web UI

React + Vite + TypeScript SPA in `ui/` (React Router, TanStack Query, Tailwind v4), embedded into the
binary with `rust-embed` from `ui/dist`. Sign in with the master key (kept in `localStorage`, sent as
`Bearer` on every `/v1` call; a `401` signs you out). Pages: projects, API keys (plaintext shown once),
logs explorer (search, filters, facets) and traces (list, waterfall detail, link to a trace's logs).
UI responses carry `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` and a
strict CSP (no inline scripts).

```sh
cd ui && npm install
npm run dev        # Vite on :5173, proxies /v1, /api, /health to http://127.0.0.1:8080
npm run typecheck
npm run build      # -> ui/dist, embedded at the next cargo build
```

Without `ui/dist` the binary still builds (so `cargo test`/clippy need no Node); `/` then shows
"UI not built". The Dockerfile builds the UI in a Node stage.

## Configuration

| Env | Default | |
|---|---|---|
| `LISTEN_ADDR` | `0.0.0.0:8080` | the single HTTP listener |
| `MASTER_KEY` | required, >= 16 chars | `Authorization: Bearer` secret for `/v1/projects/...` and the UI login |
| `DATABASE_URL` | required | engine's dedicated Postgres |
| `RETENTION_DAYS` | `14` | logs (by `timestamp`) and spans (by `start_time`) older than this are deleted, at startup then daily |
| `LOGHUB_MAX_REQUEST_BYTES` | `1000000` | -> 413 (OTLP: also applies to the decompressed gzip body) |
| `LOGHUB_MAX_BATCH_RECORDS` | `100` | -> 422 |
| `LOGHUB_MAX_MESSAGE_BYTES` / `_ATTRIBUTES_BYTES` / `_STACKTRACE_BYTES` / `_NESTING_DEPTH` | `102400` / `65536` / `65536` / `5` | per-record rejection |
| `RUST_LOG` | `info` | |

## Build and test (no host Rust toolchain: everything runs in Docker)

`./cargo.sh <args>` runs `cargo <args>` in a `rust:1` container with cached registry/target volumes.

```sh
# build / lint / format
./cargo.sh build
./cargo.sh clippy --all-targets -- -D warnings
./cargo.sh fmt --check

# tests need a real Postgres: start the engine DB, then join its network namespace
docker compose -f ../compose.yaml up -d --wait engine_database
DOCKER_ARGS="--network container:$(docker compose -f ../compose.yaml ps -q engine_database) \
  -e DATABASE_URL=postgres://engine:engine@localhost:5432/engine" ./cargo.sh test
```

`#[sqlx::test]` gives each test its own migrated database and drops it when the test passes (a failed test keeps its database for inspection until the next run).

Run the service: `docker compose up -d --build engine` (from the repo root; dev defaults in
`compose.yaml`, override with `ENGINE_MASTER_KEY`, `ENGINE_POSTGRES_PASSWORD`).

## Ingestion API

`POST /api/v1/logs`, `Authorization: Bearer lh_<64 hex>`, body `{"records": [...]}`: identical
contract to the former PHP endpoint (spec 04). `202 {"accepted","rejected","errors":[{"index","reason"}]}`
is returned only after the batch is committed. `401 {"error":"invalid_api_key"}` (missing, unknown or
revoked key), `413 {"error":"request_too_large","max_bytes"}`, `422 {"error":"too_many_records","max_records"}`,
`400` invalid JSON, `422` if `records` is not an array, `503` when the database is unavailable (retry).
Optional `trace_id` (<= 32 chars) and `span_id` (<= 16) are stored. The environment column is read from
`resource["deployment.environment.name"]` (what the bundle sends), falling back to `resource.environment`.

## OTLP trace ingestion

`POST /v1/traces` on the same port, so `OTEL_EXPORTER_OTLP_ENDPOINT=http://host:8080` plus
`OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer lh_...` works with any OTLP/HTTP exporter
(`OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf`).

- Body: `application/x-protobuf` `ExportTraceServiceRequest`. **`application/json` (OTLP/JSON) is not
  supported: 415.** `Content-Encoding: gzip` (or `identity`) is supported; the decompressed size is also
  capped by `LOGHUB_MAX_REQUEST_BYTES` (413).
- `200` with a protobuf `ExportTraceServiceResponse`; spans rejected for an invalid trace/span/parent id
  (all-zero or wrong length), empty name, missing start time or end < start are counted in
  `partial_success.rejected_spans` (+ `error_message` of the first one), the valid ones are stored.
  Errors use the JSON error shape: `400` undecodable body/gzip, `401 invalid_api_key`, `413`, `415`, `503`.
- One transaction per request, `ON CONFLICT (project_id, trace_id, span_id) DO NOTHING`: exporter retries
  are idempotent (the first copy of a span wins).
- Stored: ids as lowercase hex, kind (`internal|server|client|producer|consumer|unspecified`), start/end
  (timestamptz = microseconds) and `duration_ns` (exact, from the nanos), status (`unset|ok|error`) +
  message, `service.name` (default `unknown_service`), environment (`deployment.environment.name`, else
  `environment`, else `deployment.environment`), scope name/version, resource and span attributes as flat
  JSON maps (keys keep their dots; bytes as base64), events `[{name, timestamp, attributes}]`.
  **Span links are dropped.**

### Trace search (admin API)

`POST /v1/projects/{id}/traces/search`
```json
{"range": {"from": null, "to": null},
 "filters": [{"field": "attributes.http.response.status_code", "operator": "greater_than_or_equal", "value": 500}],
 "limit": 50, "cursor": {"start_time": "2026-09-05T10:00:00Z", "trace_id": "<32 hex>"}}
```
Response: `{"traces": [{"trace_id","root":{"span_id","name","service_name"}|null,"start_time","duration_ns",
"span_count","error_count","services":[...]}], "next_cursor": {...}|null, "has_more": bool}`.

- A trace matches when **at least one span** with `start_time` in `range` matches **all** filters. The row
  then summarises **all stored spans of the trace** (also those outside the range): `start_time` = earliest
  span start, `duration_ns` = latest end - earliest start (microsecond precision), counts over all spans.
  `root` is the earliest span without a parent (`null` if none arrived). Ordered by trace start desc,
  keyset on `(start_time, trace_id)`; `limit` 1..=200 (default 50).
- Same operators and 422 rules as log search. Fields: `service.name`, `name`, `kind`, `status` (text),
  `duration` (ns, integer), `environment`, `trace.id`, `attributes.<key>`, `resource.<key>`. Because OTel
  keys contain dots and attributes are stored flat, everything after `attributes.`/`resource.` is **one
  key** (`attributes.http.request.method`); nested (kvlist) values are not filterable.
- No facets endpoint for traces.

`GET /v1/projects/{id}/traces/{trace_id}` -> `{"trace_id","spans":[Span]}` ordered by start (404 if the
project has no such trace; max 10000 spans):
```json
Span = {"trace_id","span_id","parent_span_id"|null,"name","kind","start_time","end_time","duration_ns",
        "status":{"code":"unset|ok|error","message"},"service_name","environment",
        "scope":{"name","version"},"resource":{...},"attributes":{...},
        "events":[{"name","timestamp","attributes":{...}}]}
```

## Admin API (`Authorization: Bearer <MASTER_KEY>`, else `401 {"error":"unauthorized"}`)

Errors: `{"error": code, "message"?}` with 400 (bad JSON/path), 404, 422 (invalid request), 503/500 (storage).

### `GET /health` (no auth)
`200 {"status":"ok","version":"0.1.0"}`; `503 {"status":"unavailable",...}` if the DB is down.

### Projects and keys
| | |
|---|---|
| `POST /v1/projects` `{"name"}` | 201 `{"id","name","created_at"}` |
| `GET /v1/projects` | `[project, ...]` |
| `GET /v1/projects/{id}` | project / 404 |
| `POST /v1/projects/{id}/keys` `{"label"}` | 201 `{"id","project_id","label","created_at","revoked_at":null,"key":"lh_..."}`; **`key` is shown once**, only its SHA-256 is stored |
| `GET /v1/projects/{id}/keys` | `[{"id","project_id","label","created_at","revoked_at"}]` (no plaintext) |
| `POST /v1/projects/{id}/keys/{key_id}/revoke` | 200 key; idempotent (keeps the first `revoked_at`) |

### Log search (maps 1:1 onto `LogSearchStoreInterface`)

All request fields are optional.

`POST /v1/projects/{id}/logs/search` -> `search(LogSearchQuery)`
```json
{
  "range":   {"from": "2026-09-05T00:00:00Z", "to": null},
  "search":  "text matched (ILIKE) against message and exception.message",
  "filters": [{"field": "service.name", "operator": "equals", "value": "billing"}],
  "sort":    {"field": "timestamp", "direction": "desc"},
  "limit":   50,
  "cursor":  {"timestamp": "2026-09-05T10:00:01.123456Z", "id": "<uuid>"}
}
```
Response: `{"records": [Log], "next_cursor": {"timestamp","id"} | null, "has_more": bool}` where
```json
Log = {"id","project_id","timestamp","severity":{"text","number"},"message","channel",
       "resource":{...},"attributes":{...},"trace_id","span_id","exception":{"type","message","stacktrace"}|null}
```
- `limit` 1..=1000 (default 50). Order is `<sort.field> <dir>, id <dir>`; `cursor` is keyset on
  `(timestamp, id)` and requires `sort.field = "timestamp"`. Pass the previous `next_cursor` back.
- `sort.field`: any column field below (not a JSON path). `range.from <= range.to`, both inclusive.
- Filters are ANDed. `operator` is a `FilterOperator` value: `equals`, `not_equals`, `contains`,
  `not_contains` (ILIKE, `%`/`_`/`\` escaped), `greater_than`, `greater_than_or_equal`, `less_than`,
  `less_than_or_equal`, `exists`, `not_exists` (no value), `before` (<), `after` (>),
  `between` (`value: [lo, hi]`, inclusive).
- Fields: `timestamp` (RFC 3339 values), `severity` (number), `severity.text`, `message`, `channel`,
  `service.name`, `service.version`, `environment`, `host.name`, `trace.id`, `span.id`, and JSON paths
  `attributes.<a.b.c>`, `resource.<...>`, `exception.<...>` (segments `[A-Za-z0-9_]+`).
- JSON-path values keep their type: a JSON number compares numerically, a boolean as boolean, a string
  as text (so `"1"` also matches `1`). Rows holding a different type at that path simply don't match.
- Any invalid query (unknown field/operator, bad value, bad cursor/sort/limit) is `422`.

`POST /v1/projects/{id}/logs/count` -> `countUpTo`: same body (+ `"max"`, default 10000; sort/limit/cursor
ignored) -> `{"count": n}` saturating at `max`.

`GET /v1/projects/{id}/logs/facets/{field}?from=&to=` -> `distinctValues`: `field` in `service.name`,
`environment`, `channel`; returns `{"values": [...]}` (sorted, max 200). Encode `+` as `%2B` in `from`/`to` or use `Z`.

`GET /v1/projects/{id}/logs/{log_id}` -> `find`: a Log or 404 (never crosses projects).

## Schema notes

`migrations/0002_spans.sql`: `spans` (PK `(project_id, trace_id, span_id)`, promoted columns + JSONB), indexes
for project/time listing, root spans, service filter and the retention purge.

`migrations/0001_init.sql`: `projects`, `api_keys` (unique hashed key), `logs` (promoted columns + JSONB
`resource`/`attributes`/`exception`). Indexes: `(project_id, timestamp DESC, id DESC)`, severity / service /
environment composites, `(timestamp)` for the purge, partial `(trace_id)`. No GIN on `attributes`: the filters use `#>>`, which GIN cannot serve; add
one with the first `@>` filter.
