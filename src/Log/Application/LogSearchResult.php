<?php

declare(strict_types=1);

namespace App\Log\Application;

use App\Log\Domain\LogRecordCollection;

final readonly class LogSearchResult
{
    public function __construct(
        public LogRecordCollection $records,
        public ?LogCursor $nextCursor,
        public bool $hasMore,
    ) {
    }
}
