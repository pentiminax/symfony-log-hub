# 5. Domain and Data Model

## 5.1 Canonical LogRecord

Canonical structure:

```text
LogRecord
├── id
├── projectId
├── timestamp
├── severity
├── message
├── channel
├── resource
├── attributes
├── trace
└── exception
```

Suggested value objects:

- `LogId`
- `ProjectId`
- `Severity`
- `ResourceAttributes`
- `LogAttributes`
- `TraceContext`
- `ExceptionData`

The domain must not contain PostgreSQL/JSONB/Doctrine/OpenTelemetry classes.

## 5.2 PostgreSQL table

Recommended hybrid representation:

```text
log_record
────────────────────────────────────────
id                  UUIDv7
project_id          UUID
timestamp           timestamptz
severity_text       varchar(16)
severity_number     smallint
message             text
channel             varchar(100) null
service_name        varchar(255)
service_version     varchar(100) null
environment         varchar(100) null
host_name           varchar(255) null
trace_id            varchar(32) null
span_id             varchar(16) null
resource            jsonb
attributes          jsonb
exception           jsonb null
ingested_at         timestamptz
```

Promote high-value fields to columns while retaining their canonical data in JSON where appropriate.

## 5.3 ID strategy

Use UUIDv7 for temporal locality and stable tie-breaking.

Default ordering:

```sql
ORDER BY timestamp DESC, id DESC
```

## 5.4 Initial indexes

Start conservatively:

```sql
CREATE INDEX idx_log_project_timestamp
ON log_record (project_id, timestamp DESC);

CREATE INDEX idx_log_project_severity_timestamp
ON log_record (project_id, severity_number, timestamp DESC);

CREATE INDEX idx_log_project_service_timestamp
ON log_record (project_id, service_name, timestamp DESC);

CREATE INDEX idx_log_project_environment_timestamp
ON log_record (project_id, environment, timestamp DESC);
```

Add JSON expression/GIN indexes only after real query patterns justify them.

## 5.5 Field catalog

Maintain a lightweight project-scoped catalog of observed fields:

```text
log_field
────────────────────────────
project_id
path
type
last_seen_at
```

Examples:

```text
attributes.payment_id        integer
attributes.provider          string
attributes.retry             integer
attributes.customer.country  string
```

Field discovery is auxiliary and must not become a hard dependency for storing logs.
