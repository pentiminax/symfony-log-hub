<?php

declare(strict_types=1);

namespace App\Log\Application;

use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;

interface LogSearchStoreInterface
{
    public function search(LogSearchQuery $query): LogSearchResult;

    public function find(LogId $id): ?LogRecord;
}
