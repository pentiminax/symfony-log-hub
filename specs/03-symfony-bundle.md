# 3. Symfony Bundle

## 3.1 Purpose

Provide transparent Monolog integration for Symfony applications.

Expected installation:

```bash
composer require loghub/symfony-bundle
```

Minimal configuration:

```yaml
loghub:
    endpoint: '%env(LOGHUB_ENDPOINT)%'
    api_key: '%env(LOGHUB_API_KEY)%'

    service:
        name: 'billing-api'
        version: '%env(APP_VERSION)%'

    environment: '%kernel.environment%'
```

Monolog configuration:

```yaml
monolog:
    handlers:
        loghub:
            type: service
            id: loghub.monolog_handler
            level: info
```

Application logging remains unchanged:

```php
$this->logger->info('Payment completed', [
    'payment_id' => 123,
    'provider' => 'stripe',
]);
```

## 3.2 Internal pipeline

```text
Monolog
  → LogHubHandler
  → MonologRecordMapper
  → LogRecord
  → LogEnricherInterface[]
  → AttributeRedactor
  → LogBuffer
  → BatchTransportInterface
  → HttpBatchTransport
```

## 3.3 Enrichment

Default resource attributes should include when available:

- `service.name`
- `service.version`
- `deployment.environment.name`
- `host.name`

Default request attributes should include when available:

- `http.request.method`
- `url.path`
- `client.address`
- `user.id`
- `request.id`

Custom enrichment uses an extensible contract:

```php
interface LogEnricherInterface
{
    public function enrich(LogRecord $record): LogRecord;
}
```

Symfony autoconfiguration should allow projects to add enrichers without replacing the handler.

## 3.4 Context mapping

Unrecognized Monolog context values become `attributes` and preserve JSON-compatible types:

- string
- integer
- float
- boolean
- null
- array
- object/map

PHP objects must not be serialized blindly.

## 3.5 Exception extraction

If context contains an exception, map it to structured exception data:

- `type`
- `message`
- `stacktrace`
- `code`

## 3.6 Buffering

Default recommendations:

```yaml
loghub:
    batch:
        max_records: 100
        max_bytes: 1000000
```

Flush conditions:

- batch reaches record limit
- batch reaches byte limit
- end of HTTP request
- safe shutdown fallback
- after Messenger message where integration is available

## 3.7 Fail-open requirement

Transport failure must never propagate into application business execution.

Default transport timeout recommendation: 500 ms or lower depending on deployment.

No disk spool and no durable retry in V1.
