<?php

declare(strict_types=1);

namespace App\Trace\Application;

use App\Trace\Domain\TraceId;

final readonly class TraceCursor
{
    public function __construct(
        public \DateTimeImmutable $startTime,
        public TraceId $traceId,
    ) {
    }
}
