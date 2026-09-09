<?php

declare(strict_types=1);

namespace App\Log\Domain;

final readonly class TraceContext
{
    public function __construct(
        public string $traceId,
        public string $spanId,
    ) {
    }
}
