# loghub/symfony-bundle

PHP SDK for [LogHub](../../README.md): Monolog log shipping, trace correlation, optional OpenTelemetry
tracing, an engine API client and `loghub:*` console commands. Requires PHP 8.4 and Symfony 8.1.

## Install

```bash
composer require loghub/symfony-bundle
```

From a monorepo checkout, use a path repository in the app's `composer.json`
(`{"type": "path", "url": "../packages/symfony-bundle"}`) and require `loghub/symfony-bundle:@dev`.

```php
// config/bundles.php
LogHub\SymfonyBundle\LogHubBundle::class => ['all' => true],
```

```dotenv
# .env.local
LOGHUB_URL=http://127.0.0.1:8080
LOGHUB_API_KEY=lh_...        # project key, create it in the LogHub UI or with loghub:key:create
```

## Configuration reference

```yaml
# config/packages/loghub.yaml
loghub:
    url: '%env(LOGHUB_URL)%'                      # required. Engine base URL
    api_key: '%env(default::LOGHUB_API_KEY)%'     # project key (lh_...). Empty: nothing is shipped or traced
    master_key: '%env(default::LOGHUB_MASTER_KEY)%' # only for LogHubClient and the loghub:* commands
    service:
        name: my-app                              # required. Sent as service.name
        version: null                             # service.version
    environment: '%kernel.environment%'           # deployment.environment.name
    level: info                                   # minimum Monolog level shipped
    timeout: 0.5                                  # seconds, per log batch request
    short_class_names: true                       # shorten FQCNs in log messages/attributes
    batch:
        max_records: 100                          # flush when the buffer reaches this many records...
        max_bytes: 1000000                        # ...or this many bytes
    redaction:
        extra_keys: []                            # extra context keys to mask (on top of password, token, secret, authorization, cookie, api_key...)
    tracing:
        enabled: false                            # see "Tracing"
```

## Log shipping

The bundle exposes a Monolog handler service, `loghub.monolog_handler`. Register it in Monolog:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        loghub:
            type: service
            id: loghub.monolog_handler
            channels: ['!http_client', '!event']   # avoid logging the shipper's own traffic
```

Records are buffered and sent in one request on `kernel.terminate`, `console.terminate` and after each
Messenger message. Shipping is fail-open: engine errors are written to `error_log`, never thrown.
Each record is enriched with resource attributes (service, environment, host), request attributes
(request id, user id) and, when available, the trace context. Sensitive keys are redacted.

To add your own attributes, implement `LogHub\SymfonyBundle\Enricher\LogEnricherInterface` (autoconfigured).

## Custom attributes

Anything you put in the Monolog `context` (or `extra`) is shipped as a log attribute:

```php
$logger->info('Order placed', ['order_id' => 42, 'user' => ['plan' => 'pro']]);
```

In the web UI, the **Attributes…** picker next to the filters lists these keys (`order_id`, `user.plan`), filters on them with suggested values, and adds them as table columns. Keys are discovered from the 10 000 most recent logs in the selected time range; arrays and keys with characters other than letters, digits and `_` are not offered.

## Trace correlation

If `open-telemetry/api` is installed, every log gets the `trace_id` and `span_id` of the active
OpenTelemetry span, so the UI links a trace to its logs (and back). This works with any OpenTelemetry
setup, not only the bundle's tracing.

## Tracing

```bash
composer require open-telemetry/sdk open-telemetry/exporter-otlp nyholm/psr7 symfony/http-client
```

```yaml
loghub:
    tracing: { enabled: true }
```

Enabling tracing without the SDK and exporter packages fails at container build with a clear message.
Spans are exported over OTLP/HTTP (protobuf) to `<url>/v1/traces` with `Authorization: Bearer <api_key>`
(2 s timeout, one retry) and flushed when the request, console command or Messenger message ends.
The resource carries `service.name`, `service.version` and `deployment.environment.name` from the config.
With an empty `api_key`, nothing is exported and the app is unaffected.

What is traced, with no other setup:

- **HTTP**: one `SERVER` span per main request (`GET /path`), with method, path, route and status code;
  exceptions are recorded and 5xx responses are marked as errors. Paths starting with `/_` (profiler,
  toolbar) are skipped.
- **Messenger** (if `symfony/messenger` is installed): the context of the dispatching span travels in a
  `TraceContextStamp`; the worker starts a `CONSUMER` span `process <MessageClass>` as its child, active
  while the handlers run, so handler logs carry its span id. Failures are recorded. Retries stay in the
  original trace.

If the app already configures OpenTelemetry itself, leave `tracing` disabled: the bundle registers its own
`TracerProviderInterface`/`TracerInterface` services and HTTP/Messenger spans. Trace correlation works either way.

Custom spans: inject `OpenTelemetry\API\Trace\TracerInterface` (or `OpenTelemetry\SDK\Trace\TracerProviderInterface`).

```php
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;

$span = $tracer->spanBuilder('SELECT 1')->setSpanKind(SpanKind::KIND_CLIENT)->startSpan();
try {
    $connection->fetchOne('SELECT 1');
} finally {
    $span->end();   // becomes a child of the active request/consumer span
}
```

## API client

`LogHub\SymfonyBundle\Client\LogHubClient` (needs `master_key`) wraps the engine admin API:
`health()`, `createProject()`, `listProjects()`, `getProject()`, `createApiKey()`, `listApiKeys()`,
`revokeApiKey()`, `searchLogs()`, `countLogs()`, `logFacets()`, `findLog()`, `searchTraces()`, `getTrace()`.
Errors throw `EngineException` subclasses (`EngineUnauthorizedException`, `EngineUnavailableException`,
`InvalidEngineRequestException`).

```php
$project = $client->createProject('billing');
$key = $client->createApiKey($project->id, 'prod');   // $key->key is shown only once
$page = $client->searchLogs($project->id, ['filters' => [['field' => 'severity.text', 'operator' => 'equals', 'value' => 'ERROR']]]);
```

## Console commands (need `master_key`)

| Command | |
|---|---|
| `loghub:project:create <name>` | create a project |
| `loghub:project:list` | list projects |
| `loghub:key:create <project-id> <label>` | generate an ingestion key (printed once) |
| `loghub:key:revoke <project-id> <key-id>` | revoke a key (its id, from `listApiKeys()`, not the `lh_...` value) |

## Tests

```bash
composer install && vendor/bin/phpunit
```
