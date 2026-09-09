# 2. Architecture

## 2.1 Style

Use a **modular Symfony monolith** with Clean/Hexagonal Architecture boundaries.

Organize by functional module first, then by layer.

```text
src/
├── Log/
│   ├── Domain/
│   ├── Application/
│   └── Infrastructure/
├── Project/
│   ├── Domain/
│   ├── Application/
│   └── Infrastructure/
├── Security/
└── UI/
```

## 2.2 Main data flow

```text
Symfony application
  → Monolog
  → LogHub Symfony Bundle
  → normalize / enrich / redact
  → in-memory batch buffer
  → HTTP JSON
  → ingestion adapter
  → canonical LogRecord
  → application use case
  → LogIngestStoreInterface
  → PostgreSQL adapter

Browser
  → Discover UI
  → LogSearchQuery
  → LogSearchStoreInterface
  → PostgreSQL search adapter
```

## 2.3 Required ports

### Write port

```php
interface LogIngestStoreInterface
{
    public function storeBatch(LogRecordCollection $records): void;
}
```

### Read port

```php
interface LogSearchStoreInterface
{
    public function search(LogSearchQuery $query): LogSearchResult;

    public function find(LogId $id): ?LogRecord;
}
```

Do not expose Doctrine repositories or SQL builders outside Infrastructure.

## 2.4 Engine replacement requirement

A future engine adapter must be able to implement the same application-level search semantics:

- equality / inequality
- contains / not contains
- numeric comparisons
- exists / not exists
- date range
- sorting
- cursor pagination
- text search

Future implementations may include:

- `PostgreSqlLogSearchStore`
- `ElasticsearchLogSearchStore`
- `OpenSearchLogSearchStore`
- `ClickHouseLogSearchStore`

## 2.5 ORM policy

Use Doctrine ORM for low-volume business entities such as:

- User
- Project
- API key metadata
- Settings

Use Doctrine DBAL / optimized SQL for log ingestion and search hot paths.
