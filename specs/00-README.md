# LogHub — Formal Technical Specification

**Status:** Design approved for specification  
**Date:** 2026-09-09  
**Working name:** LogHub  

LogHub is a minimal self-hosted log exploration platform inspired by Kibana Discover, without dashboards, APM, alerting, tracing UI, or analytics in the initial release.

The first supported client is Symfony through a dedicated Monolog bundle. The server is intentionally designed as an engine-agnostic log backend so that PostgreSQL can later be replaced by Elasticsearch, OpenSearch, ClickHouse, or another search/storage engine without changing the application core or UI contracts.

## Documents

- `01-product-scope.md` — scope, goals, non-goals, success criteria
- `02-architecture.md` — clean architecture and module boundaries
- `03-symfony-bundle.md` — Symfony bundle and Monolog integration
- `04-ingestion-api.md` — ingestion contract and API key behavior
- `05-domain-data-model.md` — canonical model and PostgreSQL representation
- `06-search-filtering.md` — search, filters, cursor pagination, field catalog
- `07-ui-ux.md` — Discover-style UI/UX specification
- `08-security-retention.md` — authentication, redaction, retention, fail-open behavior
- `09-testing-acceptance.md` — test strategy and acceptance criteria
- `10-future-evolution.md` — OTLP/OpenTelemetry, alternate engines, multi-tenancy
- `SPEC-FORMAL.md` — consolidated specification
- `mockups/` — approved visual direction

## Architectural principles

1. The domain must not depend on PostgreSQL, Doctrine ORM, Symfony HttpClient, DataTables, OpenTelemetry SDK, Protobuf, Elasticsearch, or ClickHouse.
2. Write and search ports are separated.
3. PostgreSQL is an infrastructure adapter, not the product model.
4. The server uses a canonical log model structurally compatible with OpenTelemetry concepts without depending on OTLP in V1.
5. Client logging is always fail-open: the observability backend must never break business execution.
