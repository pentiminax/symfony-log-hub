<?php

declare(strict_types=1);

namespace App\Log\Application;

use App\Log\Domain\ProjectId;

final readonly class LogSearchQuery
{
    /**
     * @param list<Filter> $filters
     */
    public function __construct(
        public ProjectId $projectId,
        public DateRange $range,
        public ?string $search,
        public array $filters,
        public LogSort $sort,
        public Pagination $pagination,
    ) {
    }
}
