<?php

declare(strict_types=1);

namespace App\Log\Application;

use App\Log\Domain\LogId;

final readonly class LogCursor
{
    public function __construct(
        public \DateTimeImmutable $timestamp,
        public LogId $id,
    ) {
    }
}
