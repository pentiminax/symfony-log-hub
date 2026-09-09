# 10. Future Evolution

These items are intentionally outside V1 but must remain architecturally possible.

## 10.1 OTLP/OpenTelemetry ingestion

Add an OTLP adapter mapping standard LogRecord payloads to the canonical application model.

Potential transports:

- OTLP HTTP/Protobuf
- OTLP gRPC

Protobuf remains an adapter concern.

## 10.2 Alternate engines

Potential replacements/additions:

- Elasticsearch
- OpenSearch
- ClickHouse

Application search operators must remain engine-neutral.

## 10.3 PostgreSQL scale-up path

Before replacing PostgreSQL, possible improvements include:

- time-based partitioning
- selective JSONB expression indexes
- GIN indexes where justified
- PostgreSQL FTS
- trigram search
- ingestion COPY/bulk optimizations

## 10.4 Durable delivery

Optional future transports:

- Messenger transport
- Redis/RabbitMQ queue
- Kafka
- local disk spool/replay

These implement transport ports and must not change Monolog mapping semantics.

## 10.5 Real-time UI

Mercure or SSE can be introduced later.

The UI should still treat the search query as the source of truth to avoid filter/sort inconsistencies.

## 10.6 Multi-tenancy

Introduce `Organization` above Projects:

```text
Organization
├── Users
└── Projects
    └── Services
```

All persistent project-scoped concepts must be capable of acquiring an organisation scope later.

## 10.7 Saved views

Server-side saved views may later persist:

- user
- project
- search
- filters
- columns
- date range
- sort
- auto-refresh preference

## 10.8 Query language

A KQL-like language may later compile into the same `LogSearchQuery` and filter objects. It must not bypass application-level search semantics.
