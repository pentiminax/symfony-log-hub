<?php

declare(strict_types=1);

namespace App\Log\Domain;

final readonly class LogRecord
{
    public function __construct(
        public LogId $id,
        public ProjectId $projectId,
        public \DateTimeImmutable $timestamp,
        public Severity $severity,
        public string $message,
        public ?string $channel,
        public ResourceAttributes $resource,
        public LogAttributes $attributes,
        public ?TraceContext $trace = null,
        public ?ExceptionData $exception = null,
    ) {
    }
}
