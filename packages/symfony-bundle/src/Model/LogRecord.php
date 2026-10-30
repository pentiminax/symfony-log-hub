<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Model;

final readonly class LogRecord implements \JsonSerializable
{
    public function __construct(
        public \DateTimeImmutable $timestamp,
        public Severity $severity,
        public string $message,
        public ?string $channel,
        public ResourceAttributes $resource,
        public LogAttributes $attributes,
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?ExceptionData $exception = null,
    ) {
    }

    public function withResource(ResourceAttributes $resource): self
    {
        return new self(
            $this->timestamp,
            $this->severity,
            $this->message,
            $this->channel,
            $resource,
            $this->attributes,
            $this->traceId,
            $this->spanId,
            $this->exception,
        );
    }

    public function withAttributes(LogAttributes $attributes): self
    {
        return new self(
            $this->timestamp,
            $this->severity,
            $this->message,
            $this->channel,
            $this->resource,
            $attributes,
            $this->traceId,
            $this->spanId,
            $this->exception,
        );
    }

    public function withMessage(string $message): self
    {
        return new self(
            $this->timestamp,
            $this->severity,
            $message,
            $this->channel,
            $this->resource,
            $this->attributes,
            $this->traceId,
            $this->spanId,
            $this->exception,
        );
    }

    public function withTrace(string $traceId, string $spanId): self
    {
        return new self(
            $this->timestamp,
            $this->severity,
            $this->message,
            $this->channel,
            $this->resource,
            $this->attributes,
            $traceId,
            $spanId,
            $this->exception,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.v\Z'),
            'severity' => $this->severity,
            'message' => $this->message,
            'channel' => $this->channel,
            'resource' => $this->resource,
            'attributes' => $this->attributes,
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'exception' => $this->exception,
        ];
    }
}
