# 1. Product Scope

## 1.1 Goal

Provide a minimal, clear, fast log ingestion and exploration product for small initial volumes while keeping the architecture scalable and storage-engine agnostic.

## 1.2 Initial deployment model

- Self-hosted
- Single organisation per installation
- Multiple users
- Multiple projects
- A project can contain multiple services
- All authenticated users can access all projects in V1

The architecture must allow an `OrganizationId` boundary to be introduced later without rewriting the core domain.

## 1.3 Initial scale

Target initial load: **less than 100,000 logs/day**.

The system should not introduce premature distributed infrastructure. PostgreSQL is the initial engine, with clean ports allowing replacement later.

## 1.4 V1 capabilities

- Symfony Monolog bundle
- Buffered HTTP batch delivery
- API key per project
- Canonical structured log model
- Automatic enrichment
- Configurable recursive redaction
- Project-level retention
- PostgreSQL + JSONB storage
- Dynamic custom fields
- Global text search on message and exception message
- Per-column filters
- Dynamic columns
- Time range filtering
- Manual refresh and polling auto-refresh
- Log detail side panel
- User login
- Project selector

## 1.5 Explicit non-goals for V1

- Dashboards
- Alerting
- APM
- Distributed trace visualization
- Metrics
- OTLP ingestion
- Protobuf transport
- Kafka/RabbitMQ requirement
- Local disk spool
- Retry queues
- Saved server-side searches
- KQL/Lucene query language
- Regex/fuzzy search
- Multi-tenant SaaS billing/isolation
- Real-time Mercure streaming
