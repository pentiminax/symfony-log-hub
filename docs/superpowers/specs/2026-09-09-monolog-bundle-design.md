# Symfony Monolog Bundle — Design

**Date:** 2026-09-09
**Status:** Approved for implementation planning
**Source spec:** `specs/03-symfony-bundle.md`, `specs/04-ingestion-api.md` (payload shape), `specs/08-security-retention.md` (redaction rules)

## 1. Purpose

Implement the client-side Symfony bundle described in spec 03: transparent Monolog
integration that maps log records to LogHub's canonical JSON shape, enriches and
redacts them, buffers them in memory, and ships them to the LogHub ingestion API
(`POST /api/v1/logs`) without ever failing the host application.

## 2. Package location and boundaries

- Location: `packages/monolog-bundle/` inside the LogHub monorepo (not a separate
  repo yet — decided to keep iteration fast; extraction to its own repo remains
  possible later without code changes since the package is already self-contained).
- Composer package name: `loghub/monolog-bundle`.
- Namespace: `LogHub\MonologBundle`.
- Registered in root `composer.json` as a path repository:
  ```json
  "repositories": [{"type": "path", "url": "packages/monolog-bundle"}]
  ```
  and required as `"loghub/monolog-bundle": "*"`.
- The bundle has **no dependency on `App\*`**. It ships with its own domain
  value objects (`LogHub\MonologBundle\Model\*`), duplicating shapes already
  present in `App\Log\Domain` by necessity: this package must be installable in
  any third-party Symfony application, independent of the LogHub server codebase.
- Bundle dependencies: `monolog/monolog`, `symfony/http-client`,
  `symfony/http-kernel` (bundle base class + `kernel.terminate` event),
  `symfony/config`, `symfony/dependency-injection`. No `symfony/serializer`:
  the wire payload uses dotted keys (`service.name`) inside JSON objects that
  don't map cleanly onto normalizer conventions, so each model implements
  `JsonSerializable` directly for full control over the exact shape from
  spec 04.3.

## 3. Domain model (`LogHub\MonologBundle\Model`)

All `readonly` value objects, immutable:

- `LogRecord` — timestamp, severity, message, channel, resource, attributes,
  traceId/spanId, exception
- `Severity` — text + number (matches Monolog level mapping)
- `ResourceAttributes` — service.name, service.version,
  deployment.environment.name, host.name
- `LogAttributes` — free-form associative map of JSON-compatible scalars/arrays
- `TraceContext` — traceId, spanId (nullable)
- `ExceptionData` — type, message, stacktrace, code

Each implements `JsonSerializable` to produce exactly the shape in spec 04.3.

## 4. Pipeline (spec 3.2)

```text
Monolog\LogRecord
  → LogHubHandler (extends AbstractProcessingHandler)
  → MonologRecordMapper::map(): LogRecord
  → LogEnricherInterface[]::enrich() (autoconfigured, applied in registration order)
  → AttributeRedactor::redact()
  → LogBuffer::add() (flush triggered on limit)
  → BatchTransportInterface::send() → HttpBatchTransport
```

### 4.1 `LogHubHandler`

Extends `Monolog\Handler\AbstractProcessingHandler`. `write()` delegates to
mapper → enrichers → redactor → buffer. Never throws: any exception raised
inside the pipeline is caught here and dropped (spec 3.7 / 8.5) — a broken
enricher must not break application logging entirely; other Monolog handlers
on the same stack keep working regardless.

### 4.2 `MonologRecordMapper`

Maps a `Monolog\LogRecord` to `LogHub\MonologBundle\Model\LogRecord`:

- `datetime` → `timestamp` (ISO-8601 UTC, milliseconds)
- `level`/`levelName` → `Severity` (text + PSR-3-to-OTel-style number mapping)
- `message`, `channel` copied directly
- `context`: recognized keys (`exception`) extracted per 4.3 below; everything
  else becomes `attributes`, restricted to JSON-compatible types (string, int,
  float, bool, null, array, associative array) per spec 3.4 — PHP objects
  without a defined mapping are dropped, not blindly serialized
- `extra` merged into `attributes`

### 4.3 Exception extraction (spec 3.5)

If `context['exception']` is a `\Throwable`, map to `ExceptionData`:
`type` (`::class`), `message`, `stacktrace` (`getTraceAsString()`), `code`.

### 4.4 Enrichers (spec 3.3)

`LogEnricherInterface { enrich(LogRecord $record): LogRecord }`, tagged
`loghub.enricher` via `#[AsTaggedItem]`/autoconfiguration — third-party bundles
add enrichers by implementing the interface, no handler replacement needed.

Two default enrichers, registered first so custom enrichers can see/override
their output:

- `ResourceAttributesEnricher` — fills `service.name`/`service.version` from
  bundle config, `deployment.environment.name` from the `environment` config
  key, `host.name` from `gethostname()`.
- `RequestAttributesEnricher` — reads the current `RequestStack` request (if
  any — no-op outside HTTP context, e.g. CLI): `http.request.method`,
  `url.path`, `client.address`, `user.id` (from the security token, if
  available and `symfony/security-core` is present), `request.id` (generated
  once per request via `symfony/uid`, stored as a request attribute).

### 4.5 `AttributeRedactor` (spec 8.3)

Recursively walks `attributes` (and `resource` for defense in depth). Any key
matching (case-insensitive) the default sensitive-key list — `password`,
`passwd`, `secret`, `token`, `access_token`, `refresh_token`, `authorization`,
`cookie`, `set-cookie`, `api_key`, `apikey` — has its value replaced with the
string `[REDACTED]`, regardless of nesting depth. Extra keys are configurable
via `loghub.redaction.extra_keys`.

### 4.6 `LogBuffer`

In-memory, bounded by `max_records` (default 100) and `max_bytes` (default
1_000_000, estimated via `strlen(json_encode(...))` per record). Adding a
record that would exceed either limit triggers an immediate flush of the
buffer (record still gets added to the now-empty buffer afterward). Flush
delegates to `BatchTransportInterface::send(array $records)` and clears state
regardless of transport outcome (never retries, never spools — spec 3.7).

### 4.7 Transport

`BatchTransportInterface { send(array $records): void }`.
`HttpBatchTransport` — POSTs `{"records": [...]}` to the configured endpoint
via `symfony/http-client`, `Authorization: Bearer <api_key>`, default timeout
500 ms (configurable). **Fail-open**: wraps the call in try/catch covering
`TransportExceptionInterface` and any client/server HTTP error; on failure the
batch is dropped (no retry, no disk spool — spec 3.7/8.5) and a diagnostic is
written via `error_log()` (or a configurable PSR-3 "internal" logger, never
the LogHub handler itself, to avoid recursive logging per spec 8.5) — never
re-thrown into the caller.

## 5. Flush triggers (spec 3.6)

- Buffer hits `max_records` or `max_bytes` → immediate flush.
- `kernel.terminate` event listener → flush after the HTTP response is sent
  to the client, so shipping logs never adds perceived request latency.
- `console.terminate` event listener → same, for CLI commands.
- `register_shutdown_function` fallback registered at handler construction →
  safety net for fatal errors or contexts without a kernel/console terminate
  event (e.g. long-running workers). Guarded to flush at most once (shutdown
  function checks if buffer is already empty from a prior flush).

Messenger-integrated flush (spec 3.6 "after Messenger message where
integration is available") is **out of scope for V1**: `symfony/messenger` is
not a guaranteed dependency of consuming applications, and kernel/console
terminate + shutdown fallback already cover the common cases. Left as a future
optional listener, added only when `symfony/messenger` is detected installed.

## 6. Bundle wiring

`LogHubBundle extends Symfony\Component\HttpKernel\Bundle\Bundle`. Standard
Flex-style `Configuration`/`Extension` pair under
`LogHub\MonologBundle\DependencyInjection`, config tree matching spec 3
exactly (`endpoint`, `api_key`, `service.name`, `service.version`,
`environment`, `batch.max_records`, `batch.max_bytes`,
`redaction.extra_keys`). Services autoconfigured; `LogEnricherInterface` tag
registered via `$container->registerForAutoconfiguration(...)`.

Monolog wiring stays manual per spec 3.1 example (`monolog.handlers.loghub`
pointing at service id `loghub.monolog_handler`) — this is standard Monolog
bundle configuration, not something the LogHub bundle needs to automate.

## 7. Testing (spec 09 acceptance intent)

Package-local `tests/` (PHPUnit, same version as root project):

- `MonologRecordMapperTest` — context/extra mapping, exception extraction,
  non-JSON-compatible value dropped.
- `AttributeRedactorTest` — default keys redacted at any nesting depth, extra
  keys, non-sensitive keys untouched.
- `LogBufferTest` — flush at record limit, flush at byte limit, buffer empty
  after flush regardless of transport success/failure.
- `ResourceAttributesEnricherTest`, `RequestAttributesEnricherTest` — correct
  fields populated from config/request, no-op outside HTTP context.
- `HttpBatchTransportTest` — mocked `HttpClientInterface`: success path posts
  expected JSON shape; network/HTTP failure is swallowed, never throws.
- `LogHubHandlerTest` — end-to-end through the pipeline with a fake transport,
  asserting a broken enricher doesn't propagate.
- Bundle extension test — `ContainerBuilder` compiles with minimal config, tag
  autoconfiguration applied.

## 8. Explicitly out of scope for V1 (per spec 3.7 and this design)

- Disk spool / durable retry on transport failure.
- Messenger-triggered flush.
- OTLP export (spec explicitly keeps this as a future separate adapter).

## 9. Non-goals / no server changes

This design touches only `packages/monolog-bundle/`. The existing ingestion
endpoint (`LogIngestionController`, `IngestLogsRequest`, `LogRecordMapper` in
`src/Log/Infrastructure/Http/`) already accepts the spec 04.3 payload shape —
verified against this design, no server-side change required.
