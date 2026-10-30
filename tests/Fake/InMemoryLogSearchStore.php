<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Log\Application\DateRange;
use App\Log\Application\Filter;
use App\Log\Application\FilterOperator;
use App\Log\Application\LogCursor;
use App\Log\Application\LogSearchQuery;
use App\Log\Application\LogSearchResult;
use App\Log\Application\LogSearchStoreInterface;
use App\Log\Application\SortDirection;
use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\LogRecordCollection;
use App\Log\Domain\ProjectId;

/**
 * Just enough of the engine's semantics for UI tests: project scoping, range, a few
 * fields and operators, timestamp keyset paging. Storage semantics are tested in the engine.
 */
final class InMemoryLogSearchStore implements LogSearchStoreInterface
{
    private const array FIELDS = ['severity', 'severity.text', 'message', 'channel', 'service.name', 'environment'];

    /** @var list<LogRecord> */
    private array $records = [];

    public function add(LogRecord ...$records): void
    {
        array_push($this->records, ...$records);
    }

    public function search(LogSearchQuery $query): LogSearchResult
    {
        $rows = $this->matching($query);
        $asc = SortDirection::ASC === $query->sort->direction;
        usort($rows, static fn (LogRecord $a, LogRecord $b): int => ($asc ? 1 : -1) * ($a->timestamp <=> $b->timestamp ?: strcmp((string) $a->id, (string) $b->id)));

        if (null !== $cursor = $query->pagination->cursor) {
            $rows = array_values(array_filter($rows, static function (LogRecord $r) use ($cursor, $asc): bool {
                $order = $r->timestamp <=> $cursor->timestamp ?: strcmp((string) $r->id, (string) $cursor->id);

                return $asc ? $order > 0 : $order < 0;
            }));
        }

        $limit = $query->pagination->limit;
        $page = array_slice($rows, 0, $limit);
        $hasMore = count($rows) > $limit;
        $last = $page[array_key_last($page)] ?? null;

        return new LogSearchResult(
            new LogRecordCollection(...$page),
            $hasMore && null !== $last ? new LogCursor($last->timestamp, $last->id) : null,
            $hasMore,
        );
    }

    public function find(ProjectId $projectId, LogId $id): ?LogRecord
    {
        foreach ($this->records as $record) {
            if ($record->projectId->equals($projectId) && $record->id->equals($id)) {
                return $record;
            }
        }

        return null;
    }

    public function countUpTo(LogSearchQuery $query, int $max = 10_000): int
    {
        return min($max, count($this->matching($query)));
    }

    public function distinctValues(ProjectId $projectId, string $field, DateRange $range): array
    {
        $values = [];
        foreach ($this->records as $record) {
            if ($record->projectId->equals($projectId) && null !== $v = $this->value($record, $field)) {
                $values[] = (string) $v;
            }
        }
        sort($values);

        return array_values(array_unique($values));
    }

    /** @return list<LogRecord> */
    private function matching(LogSearchQuery $query): array
    {
        foreach ($query->filters as $filter) {
            if (!\in_array($filter->field, self::FIELDS, true) && !str_starts_with($filter->field, 'attributes.')) {
                throw new \InvalidArgumentException(sprintf('Unknown filterable field "%s".', $filter->field));
            }
        }

        return array_values(array_filter($this->records, function (LogRecord $r) use ($query): bool {
            if (!$r->projectId->equals($query->projectId)
                || (null !== $query->range->from && $r->timestamp < $query->range->from)
                || (null !== $query->range->to && $r->timestamp > $query->range->to)
                || (null !== $query->search && !str_contains(strtolower($r->message), strtolower($query->search)))) {
                return false;
            }

            foreach ($query->filters as $filter) {
                if (!$this->matches($r, $filter)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function matches(LogRecord $record, Filter $filter): bool
    {
        $value = $this->value($record, $filter->field);

        return match ($filter->operator) {
            FilterOperator::EQUALS => $value == $filter->value,
            FilterOperator::NOT_EQUALS => $value != $filter->value,
            FilterOperator::BETWEEN => $value >= $filter->value[0] && $value <= $filter->value[1],
            default => throw new \InvalidArgumentException(sprintf('Operator "%s" is not supported by the fake.', $filter->operator->value)),
        };
    }

    private function value(LogRecord $record, string $field): mixed
    {
        return match (true) {
            'severity' === $field => $record->severity->number,
            'severity.text' === $field => $record->severity->text,
            'message' === $field => $record->message,
            'channel' === $field => $record->channel,
            'service.name' === $field, 'environment' === $field => $record->resource->get($field),
            str_starts_with($field, 'attributes.') => $record->attributes->get(substr($field, 11)),
            default => throw new \InvalidArgumentException(sprintf('Unknown filterable field "%s".', $field)),
        };
    }
}
