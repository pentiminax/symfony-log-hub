# 4. Ingestion API

## 4.1 Endpoint

```http
POST /api/v1/logs
Authorization: Bearer lh_<secret>
Content-Type: application/json
```

## 4.2 Project resolution

The request must not contain a trusted `project_id`.

The server resolves project identity from the API key.

```text
API key → Project → authorized ingestion
```

One project may contain several services through `resource.service.name`.

## 4.3 Payload

```json
{
  "records": [
    {
      "timestamp": "2026-09-09T14:45:01.231Z",
      "severity": {
        "text": "ERROR",
        "number": 17
      },
      "message": "Payment failed",
      "channel": "payment",
      "resource": {
        "service.name": "billing-api",
        "service.version": "1.4.2",
        "deployment.environment.name": "prod",
        "host.name": "api-02"
      },
      "attributes": {
        "payment_id": 123,
        "provider": "stripe",
        "http.request.method": "POST",
        "url.path": "/payments/123",
        "user.id": "42"
      },
      "trace_id": null,
      "span_id": null,
      "exception": {
        "type": "RuntimeException",
        "message": "Provider unavailable",
        "stacktrace": "..."
      }
    }
  ]
}
```

## 4.4 Required fields

At minimum:

- timestamp
- severity
- message
- `resource.service.name`

## 4.5 Partial rejection

One malformed record must not reject an otherwise valid batch.

Example response:

```http
202 Accepted
```

```json
{
  "accepted": 41,
  "rejected": 1
}
```

The implementation may return record-level validation diagnostics, but must not echo sensitive data unnecessarily.

## 4.6 Limits

The server must enforce configurable limits on:

- records per batch
- total request size
- message size
- attributes size
- stacktrace size
- nesting depth where necessary

## 4.7 Future OTLP compatibility

The proprietary V1 endpoint remains distinct from future OTLP endpoints:

```text
/api/v1/logs  → LogHub JSON API
/v1/logs      → future OTLP HTTP
:4317         → optional future OTLP gRPC
```

OTLP/Protobuf must be an ingestion adapter, not a domain dependency.
