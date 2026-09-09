# 6. Search and Filtering

## 6.1 Search contract

```php
final readonly class LogSearchQuery
{
    public function __construct(
        public ProjectId $projectId,
        public DateRange $range,
        public ?string $search,
        public array $filters,
        public LogSort $sort,
        public Pagination $pagination,
    ) {}
}
```

## 6.2 Global text search

V1 global search should search:

- `message`
- exception message

Do not implement unbounded `attributes::text ILIKE` as the primary search strategy.

## 6.3 Filter field syntax

Stable application-level field paths should include examples such as:

- `severity`
- `service.name`
- `service.version`
- `environment`
- `host.name`
- `attributes.payment_id`
- `attributes.customer.country`
- `trace.id`
- `exception.type`

The PostgreSQL adapter maps these paths to columns or JSONB extraction.

## 6.4 Field types

Supported V1 types:

- string
- integer
- float
- boolean
- datetime
- null

Preserve numeric and boolean semantics; do not coerce all JSON values to strings.

## 6.5 Operators

### String

- equals
- not equals
- contains
- not contains
- exists
- not exists

### Number

- equals
- not equals
- greater than
- greater than or equal
- less than
- less than or equal
- exists
- not exists

### Boolean

- equals
- not equals

### Date/datetime

- before
- after
- between

## 6.6 Cursor pagination

Avoid deep OFFSET pagination.

Cursor should be based on `(timestamp, id)`.

Conceptual page-next query:

```sql
WHERE (timestamp, id) < (:last_timestamp, :last_id)
ORDER BY timestamp DESC, id DESC
LIMIT :limit
```

The UI should prefer `Previous` / `Next` navigation rather than arbitrary page numbers for deep log streams.

## 6.7 DataTables boundary

UX DataTables is a presentation/interaction layer only.

It must call an application-level search endpoint or service that accepts `LogSearchQuery`. It must not generate storage-specific SQL semantics directly.
