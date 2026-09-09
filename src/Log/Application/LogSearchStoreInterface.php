<?php

declare(strict_types=1);

namespace App\Log\Application;

use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\ProjectId;

interface LogSearchStoreInterface
{
    public function search(LogSearchQuery $query): LogSearchResult;

    /**
     * Scoped by project on purpose: a log id alone must never cross a project boundary.
     */
    public function find(ProjectId $projectId, LogId $id): ?LogRecord;

    /**
     * Exact result count below $max, saturating at $max.
     *
     * Bounded rather than exact so every engine can answer it cheaply
     * (Elasticsearch's track_total_hits, a LIMITed subquery in SQL).
     */
    public function countUpTo(LogSearchQuery $query, int $max = 10_000): int;

    /**
     * Values observed for a low-cardinality field, to populate filter choices.
     *
     * @return list<string>
     */
    public function distinctValues(ProjectId $projectId, string $field, DateRange $range): array;
}
