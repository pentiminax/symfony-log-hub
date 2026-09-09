<?php

declare(strict_types=1);

namespace App\Log\Application;

use App\Log\Domain\LogRecordCollection;

interface LogIngestStoreInterface
{
    public function storeBatch(LogRecordCollection $records): void;
}
