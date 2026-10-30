<?php

declare(strict_types=1);

namespace App\Trace\Domain;

final readonly class Span
{
    /**
     * @param 'unset'|'ok'|'error'                                                        $status
     * @param array<string, mixed>                                                        $attributes
     * @param array<string, mixed>                                                        $resource
     * @param list<array{name: string, timestamp: ?string, attributes: array<string, mixed>}> $events
     */
    public function __construct(
        public string $spanId,
        public ?string $parentSpanId,
        public string $name,
        public string $kind,
        public \DateTimeImmutable $start,
        public int $durationNs,
        public string $status,
        public ?string $statusMessage,
        public string $serviceName,
        public array $attributes = [],
        public array $resource = [],
        public array $events = [],
    ) {
    }

    public function isError(): bool
    {
        return 'error' === $this->status;
    }

    /** Microseconds since the epoch (the precision the engine stores). */
    public function startUs(): int
    {
        return (int) $this->start->format('U') * 1_000_000 + (int) $this->start->format('u');
    }

    public function endUs(): int
    {
        return $this->startUs() + intdiv($this->durationNs, 1000);
    }
}
