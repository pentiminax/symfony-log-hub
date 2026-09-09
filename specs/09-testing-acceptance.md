# 9. Testing and Acceptance Criteria

## 9.1 Test layers

### Domain unit tests

Cover:

- severity mapping
- canonical value objects
- filter validation
- field type behavior
- cursor semantics

### Bundle unit tests

Cover:

- Monolog record mapping
- custom attributes
- exception extraction
- recursive redaction
- enrichment order
- batch thresholds
- fail-open transport behavior

### Application tests

Cover:

- API key → project resolution
- valid batch ingestion
- partial batch rejection
- retention calculation
- dynamic field discovery

### PostgreSQL integration tests

Use a real PostgreSQL instance for:

- JSONB type filtering
- nested field filtering
- cursor pagination
- ordering stability
- index-compatible main queries
- batch insert behavior

Do not rely exclusively on SQLite for storage adapter tests.

### UI functional tests

Cover:

- login
- project switch
- search
- time range
- add/remove filters
- add/remove dynamic columns
- row detail drawer
- filter-for-value / filter-out-value
- auto-refresh preserving active state

## 9.2 Acceptance criteria

### Ingestion

- Symfony application can send a batch through Monolog without changing logging calls.
- Unavailable LogHub never breaks the calling request or worker job.
- API key controls the target project.
- One invalid record does not reject valid records in the same batch.

### Search

- User can search by message/exception message.
- User can filter standard and custom fields.
- Numeric filters behave numerically.
- Nested custom fields can be filtered.
- Results are ordered stably by timestamp + ID.
- Deep navigation does not depend on large OFFSET values.

### UI

- Active filters are always visible.
- Dynamic fields can become columns without schema migration.
- Selecting a log does not lose current search context.
- Auto-refresh does not blank the existing table.
- Empty and transient failure states are explicit and actionable.

### Architecture

- Domain/Application code contains no Doctrine entity manager usage for log persistence.
- UI code does not construct PostgreSQL-specific query expressions.
- Storage adapters can be swapped by implementing ports.
