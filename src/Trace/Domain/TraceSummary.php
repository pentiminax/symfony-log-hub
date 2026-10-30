<?php

declare(strict_types=1);

namespace App\Trace\Domain;

final readonly class TraceSummary
{
    /**
     * @param list<string> $services
     */
    public function __construct(
        public TraceId $id,
        public ?string $rootName,
        public ?string $rootService,
        public \DateTimeImmutable $start,
        public int $durationNs,
        public int $spanCount,
        public int $errorCount,
        public array $services,
    ) {
    }
}
