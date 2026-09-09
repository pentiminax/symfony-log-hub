# LogHub V1 — Consolidated Formal Technical Specification

**Date:** 2026-09-09  
**Status:** Ready for implementation planning after review  

## A. Product definition

LogHub is a self-hosted, minimal log ingestion and exploration application inspired by Kibana Discover. V1 focuses on structured log ingestion, fast filtering, dynamic fields, and clear log inspection. Dashboards, APM, alerts, metrics, OTLP ingestion, and query languages are excluded.

## B. Deployment and tenancy

- Symfony full-stack application
- single organisation per installation in V1
- multiple authenticated users
- multiple projects
- a project may contain multiple services
- all authenticated users can access all projects in V1
- future multi-tenancy must remain possible

## C. Client integration

The initial client is a Symfony bundle integrated through Monolog.

Pipeline:

```text
Monolog → mapper → enrichers → redactor → bounded memory buffer → HTTP JSON batch
```

The bundle is fail-open and never propagates collector network failures to business execution.

## D. Ingestion

Endpoint:

```http
POST /api/v1/logs
Authorization: Bearer <project-api-key>
```

The API key determines the project. Client payloads do not select project IDs.

Batches support partial acceptance. Required log information is timestamp, severity, message, and service name.

## E. Canonical model

The canonical domain model includes:

- ID
- project ID
- timestamp
- severity text/number
- message
- channel
- resource attributes
- dynamic attributes
- trace context
- exception data

The structure is OpenTelemetry-friendly but contains no dependency on OpenTelemetry SDK, OTLP, or Protobuf.

## F. Storage

Initial engine: PostgreSQL.

Use a hybrid schema:

- indexed relational columns for frequently queried fields
- JSONB for resource, custom attributes, and exception details

Use UUIDv7 and stable ordering by `(timestamp DESC, id DESC)`.

Doctrine ORM is acceptable for users/projects/API key metadata. Log ingestion/search hot paths use DBAL/optimized SQL.

## G. Search

Application-level search is represented by `LogSearchQuery` and engine-neutral filter objects.

V1 supports:

- time range
- global text search over message and exception message
- equality and inequality
- string contains
- numeric comparisons
- exists/not exists
- datetime comparisons
- sorting
- cursor pagination

Custom JSONB fields remain filterable without schema declaration.

A project-scoped field catalog stores observed paths and types to power dynamic columns and filter UIs.

## H. UI/UX

The default authenticated screen is a Discover-style log explorer.

Main layout:

- compact global sidebar
- project selector
- global search
- visible time range
- refresh + polling auto-refresh
- active filter chips
- field selector panel
- dense logs table
- right-side log detail drawer

Default columns:

- timestamp
- severity
- message
- service
- environment

Users can add custom fields as columns immediately.

Selecting a value offers:

- Filter for value
- Filter out value
- Copy value

Selecting a row opens a right-side detail drawer without losing table context.

Detail sections:

- Overview
- Attributes
- Resource
- Exception
- Trace
- Raw JSON

V1 UI preferences may be stored in localStorage.

## I. Security

- standard Symfony login
- API key per project
- revocation/rotation
- client-side recursive redaction before transmission
- default sensitive field list
- fail-open collector transport

## J. Retention

Retention is configurable per project with a global default.

V1 uses scheduled timestamp-based deletion. Partitioning may be introduced later.

## K. Scalability contract

PostgreSQL must remain an adapter.

Future engines can implement the same read/write ports without UI or domain rewrites. Expected candidates include Elasticsearch, OpenSearch, and ClickHouse.

OTLP/Protobuf may later be introduced as ingestion adapters mapped into the same canonical model.

## L. Acceptance gates

The V1 is acceptable when:

1. Symfony apps can emit logs through normal Monolog calls.
2. Collector outages never break application requests/jobs.
3. Project identity comes only from the API key.
4. Custom fields are searchable/filterable without migrations.
5. Numeric/boolean filters preserve type semantics.
6. Search supports cursor-based navigation.
7. Dynamic fields can be added as table columns.
8. Active filters remain visible at all times.
9. Opening a log preserves current Discover context.
10. Domain/Application code remains storage-engine agnostic.
