<?php

declare(strict_types=1);

namespace App\Trace\Application;

use App\Log\Domain\ProjectId;
use App\Trace\Domain\Trace;
use App\Trace\Domain\TraceId;

interface TraceSearchStoreInterface
{
    /**
     * A trace matches when at least one of its spans matches the range and every filter.
     *
     * @throws \InvalidArgumentException when the query is invalid (unknown field, bad value, ...)
     */
    public function search(TraceSearchQuery $query): TraceSearchResult;

    /**
     * Scoped by project on purpose: a trace id alone must never cross a project boundary.
     */
    public function find(ProjectId $projectId, TraceId $id): ?Trace;
}
