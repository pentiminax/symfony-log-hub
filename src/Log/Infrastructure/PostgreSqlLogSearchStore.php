<?php

declare(strict_types=1);

namespace App\Log\Infrastructure;

use App\Log\Application\DateRange;
use App\Log\Application\Filter;
use App\Log\Application\FilterOperator;
use App\Log\Application\LogCursor;
use App\Log\Application\LogSearchQuery;
use App\Log\Application\LogSearchResult;
use App\Log\Application\LogSearchStoreInterface;
use App\Log\Application\SortDirection;
use App\Log\Domain\ExceptionData;
use App\Log\Domain\LogAttributes;
use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\LogRecordCollection;
use App\Log\Domain\ProjectId;
use App\Log\Domain\ResourceAttributes;
use App\Log\Domain\Severity;
use App\Log\Domain\TraceContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

final readonly class PostgreSqlLogSearchStore implements LogSearchStoreInterface
{
    /**
     * Stable application-level field paths (spec 06-search-filtering.md §6.3)
     * mapped to their promoted column.
     */
    private const array COLUMN_FIELDS = [
        'timestamp' => 'timestamp',
        'severity' => 'severity_number',
        'severity.text' => 'severity_text',
        'message' => 'message',
        'channel' => 'channel',
        'service.name' => 'service_name',
        'service.version' => 'service_version',
        'environment' => 'environment',
        'host.name' => 'host_name',
        'trace.id' => 'trace_id',
        'span.id' => 'span_id',
    ];

    /**
     * Fields whose observed values may be enumerated. Deliberately narrow: these are
     * low-cardinality promoted columns, so a DISTINCT over the visible window is cheap.
     */
    private const array FACET_FIELDS = [
        'service.name',
        'environment',
        'channel',
    ];

    /**
     * JSONB documents whose keys are addressable as `<prefix>.<path>` filter fields.
     */
    private const array JSON_COLUMNS = [
        'attributes.' => 'attributes',
        'resource.' => 'resource',
        'exception.' => 'exception',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function search(LogSearchQuery $query): LogSearchResult
    {
        $qb = $this->matching($query)->select('*');

        if (null !== $query->pagination->cursor) {
            if ('timestamp' !== $query->sort->field) {
                throw new \InvalidArgumentException(sprintf('Cursor pagination requires sorting by "timestamp", got "%s".', $query->sort->field));
            }

            $cursor = $query->pagination->cursor;
            $comparison = SortDirection::ASC === $query->sort->direction ? '>' : '<';
            $qb->andWhere("(timestamp, id) {$comparison} (:cursorTimestamp, :cursorId)")
                ->setParameter('cursorTimestamp', PostgreSqlTimestamp::bind($cursor->timestamp), Types::STRING)
                ->setParameter('cursorId', (string) $cursor->id);
        }

        $direction = SortDirection::ASC === $query->sort->direction ? 'ASC' : 'DESC';
        $qb->orderBy($this->resolveColumn($query->sort->field), $direction)
            ->addOrderBy('id', $direction)
            ->setMaxResults($query->pagination->limit + 1);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $hasMore = count($rows) > $query->pagination->limit;
        $records = array_map($this->hydrate(...), array_slice($rows, 0, $query->pagination->limit));

        $nextCursor = null;
        if ($hasMore && [] !== $records) {
            $last = $records[array_key_last($records)];
            $nextCursor = new LogCursor($last->timestamp, $last->id);
        }

        return new LogSearchResult(new LogRecordCollection(...$records), $nextCursor, $hasMore);
    }

    public function find(ProjectId $projectId, LogId $id): ?LogRecord
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('log_record')
            ->where('id = :id')
            ->andWhere('project_id = :projectId')
            ->setParameter('id', (string) $id)
            ->setParameter('projectId', (string) $projectId)
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : $this->hydrate($row);
    }

    public function countUpTo(LogSearchQuery $query, int $max = 10_000): int
    {
        $bounded = $this->matching($query)->select('1')->setMaxResults($max);

        return (int) $this->connection->fetchOne(
            sprintf('SELECT count(*) FROM (%s) AS bounded', $bounded->getSQL()),
            $bounded->getParameters(),
            $bounded->getParameterTypes(),
        );
    }

    public function distinctValues(ProjectId $projectId, string $field, DateRange $range): array
    {
        if (!\in_array($field, self::FACET_FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" is not facetable.', $field));
        }

        $column = self::COLUMN_FIELDS[$field];
        $qb = $this->connection->createQueryBuilder()
            ->select("DISTINCT {$column}")
            ->from('log_record')
            ->where('project_id = :projectId')
            ->andWhere("{$column} IS NOT NULL")
            ->setParameter('projectId', (string) $projectId)
            ->orderBy($column, 'ASC')
            // ponytail: DISTINCT over the visible window. If it shows up in slow query
            // logs, cache it per project+range bucket or populate the field catalog
            // (spec 05 §5.5) on ingest.
            ->setMaxResults(200);

        $this->applyRange($qb, $range);

        return array_map(strval(...), $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Every predicate of a search, without ordering, cursor or limit, so that a count
     * and a page are guaranteed to describe the same result set.
     */
    private function matching(LogSearchQuery $query): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->from('log_record')
            ->where('project_id = :projectId')
            ->setParameter('projectId', (string) $query->projectId);

        $this->applyRange($qb, $query->range);

        if (null !== $query->search && '' !== $query->search) {
            $qb->andWhere("(message ILIKE :search OR (exception #>> '{message}') ILIKE :search)")
                ->setParameter('search', '%'.addcslashes($query->search, '%_\\').'%');
        }

        foreach ($query->filters as $index => $filter) {
            $this->applyFilter($qb, $filter, $index);
        }

        return $qb;
    }

    private function applyRange(QueryBuilder $qb, DateRange $range): void
    {
        if (null !== $range->from) {
            $qb->andWhere('timestamp >= :rangeFrom')
                ->setParameter('rangeFrom', PostgreSqlTimestamp::bind($range->from), Types::STRING);
        }

        if (null !== $range->to) {
            $qb->andWhere('timestamp <= :rangeTo')
                ->setParameter('rangeTo', PostgreSqlTimestamp::bind($range->to), Types::STRING);
        }
    }

    private function applyFilter(QueryBuilder $qb, Filter $filter, int $index): void
    {
        $placeholder = 'filter'.$index;
        $expression = $this->resolveColumn($filter->field);
        $isJsonPath = $this->isJsonPath($filter->field);

        if (FilterOperator::EXISTS === $filter->operator) {
            $qb->andWhere("{$expression} IS NOT NULL");

            return;
        }

        if (FilterOperator::NOT_EXISTS === $filter->operator) {
            $qb->andWhere("{$expression} IS NULL");

            return;
        }

        if (FilterOperator::BETWEEN === $filter->operator) {
            $expression = $isJsonPath ? $this->cast($expression, $filter->value[0]) : $expression;
            $qb->andWhere("{$expression} BETWEEN :{$placeholder}From AND :{$placeholder}To")
                ->setParameter("{$placeholder}From", $this->bindValue($filter->value[0]), $this->valueType($filter->value[0]))
                ->setParameter("{$placeholder}To", $this->bindValue($filter->value[1]), $this->valueType($filter->value[1]));

            return;
        }

        if (FilterOperator::CONTAINS === $filter->operator || FilterOperator::NOT_CONTAINS === $filter->operator) {
            $not = FilterOperator::NOT_CONTAINS === $filter->operator ? 'NOT ' : '';
            $qb->andWhere("{$expression} {$not}ILIKE :{$placeholder}")
                ->setParameter($placeholder, '%'.addcslashes((string) $filter->value, '%_\\').'%');

            return;
        }

        $sqlOperator = match ($filter->operator) {
            FilterOperator::EQUALS => '=',
            FilterOperator::NOT_EQUALS => '!=',
            FilterOperator::GREATER_THAN, FilterOperator::AFTER => '>',
            FilterOperator::GREATER_THAN_OR_EQUAL => '>=',
            FilterOperator::LESS_THAN, FilterOperator::BEFORE => '<',
            FilterOperator::LESS_THAN_OR_EQUAL => '<=',
            default => throw new \InvalidArgumentException(sprintf('Unsupported filter operator "%s".', $filter->operator->value)),
        };

        $castExpression = $isJsonPath ? $this->cast($expression, $filter->value) : $expression;
        $qb->andWhere("{$castExpression} {$sqlOperator} :{$placeholder}")
            ->setParameter($placeholder, $this->bindValue($filter->value), $this->valueType($filter->value));
    }

    /**
     * JSONB paths (attributes.*, exception.*) are extracted as text (spec 06 §6.4:
     * "preserve numeric and boolean semantics"), so cast to the filter value's own
     * PHP type since no field-catalog type declaration exists yet.
     *
     * ponytail: cast-by-PHP-type is a stand-in for the field catalog (05 §5.5);
     * replace with a declared-type cast once that catalog exists.
     */
    private function cast(string $expression, mixed $value): string
    {
        return match (true) {
            is_bool($value) => "({$expression})::boolean",
            is_int($value) => "({$expression})::bigint",
            is_float($value) => "({$expression})::double precision",
            $value instanceof \DateTimeInterface => "({$expression})::timestamptz",
            default => $expression,
        };
    }

    private function valueType(mixed $value): Types|string
    {
        return match (true) {
            \is_bool($value) => Types::BOOLEAN,
            \is_int($value) => Types::INTEGER,
            \is_float($value) => Types::FLOAT,
            default => Types::STRING,
        };
    }

    /** Dates go to the driver as microsecond strings; everything else unchanged. */
    private function bindValue(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? PostgreSqlTimestamp::bind($value) : $value;
    }

    private function resolveColumn(string $field): string
    {
        if (isset(self::COLUMN_FIELDS[$field])) {
            return self::COLUMN_FIELDS[$field];
        }

        foreach (self::JSON_COLUMNS as $prefix => $column) {
            if (str_starts_with($field, $prefix)) {
                return $this->jsonPath($column, substr($field, \strlen($prefix)));
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown filterable field "%s".', $field));
    }

    private function isJsonPath(string $field): bool
    {
        foreach (array_keys(self::JSON_COLUMNS) as $prefix) {
            if (str_starts_with($field, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function jsonPath(string $column, string $path): string
    {
        $segments = explode('.', $path);

        foreach ($segments as $segment) {
            if (1 !== preg_match('/^[A-Za-z0-9_]+$/', $segment)) {
                throw new \InvalidArgumentException(sprintf('Invalid field path segment "%s".', $segment));
            }
        }

        return sprintf("%s #>> '{%s}'", $column, implode(',', $segments));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LogRecord
    {
        $trace = null;
        if (null !== $row['trace_id'] && null !== $row['span_id']) {
            $trace = new TraceContext($row['trace_id'], $row['span_id']);
        }

        $exception = null;
        if (null !== $row['exception']) {
            $decoded = json_decode((string) $row['exception'], true, flags: \JSON_THROW_ON_ERROR);
            $exception = new ExceptionData($decoded['type'], $decoded['message'], $decoded['stacktrace'] ?? null);
        }

        return new LogRecord(
            id: LogId::fromString($row['id']),
            projectId: ProjectId::fromString($row['project_id']),
            timestamp: new \DateTimeImmutable($row['timestamp']),
            severity: new Severity($row['severity_text'], (int) $row['severity_number']),
            message: $row['message'],
            channel: $row['channel'],
            resource: new ResourceAttributes(json_decode((string) $row['resource'], true, flags: \JSON_THROW_ON_ERROR)),
            attributes: new LogAttributes(json_decode((string) $row['attributes'], true, flags: \JSON_THROW_ON_ERROR)),
            trace: $trace,
            exception: $exception,
        );
    }
}
