<?php

declare(strict_types=1);

namespace App\Trace\Application;

use App\Trace\Domain\TraceSummary;

final readonly class TraceSearchResult
{
    /**
     * @param list<TraceSummary> $traces
     */
    public function __construct(
        public array $traces,
        public ?TraceCursor $nextCursor,
        public bool $hasMore,
    ) {
    }
}
