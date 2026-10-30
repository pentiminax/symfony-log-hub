<?php

declare(strict_types=1);

namespace App\Trace\Application;

use App\Log\Application\DateRange;
use App\Log\Application\Filter;
use App\Log\Domain\ProjectId;

final readonly class TraceSearchQuery
{
    /**
     * @param list<Filter> $filters
     */
    public function __construct(
        public ProjectId $projectId,
        public DateRange $range,
        public array $filters,
        public int $limit,
        public ?TraceCursor $cursor = null,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException(sprintf('Limit must be at least 1, got %d.', $limit));
        }
    }
}
