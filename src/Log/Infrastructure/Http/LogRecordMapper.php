<?php

declare(strict_types=1);

namespace App\Log\Infrastructure\Http;

use App\Log\Domain\ExceptionData;
use App\Log\Domain\LogAttributes;
use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\ProjectId;
use App\Log\Domain\ResourceAttributes;
use App\Log\Domain\Severity;
use App\Log\Domain\TraceContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates and maps one raw ingestion record (spec 04.3/04.4) into a domain LogRecord, or
 * throws InvalidLogRecordException so the caller can reject just this record and keep the
 * rest of the batch (spec 04.5). Enforces the per-record limits from spec 04.6; the
 * records-per-batch and total-request-size limits are whole-request concerns handled by
 * the controller.
 */
final readonly class LogRecordMapper
{
    /** Optional keys the sample payload (spec 04.3) sends as explicit JSON null. */
    private const OPTIONAL_KEYS = ['channel', 'attributes', 'trace_id', 'span_id', 'exception', 'project_id'];

    public function __construct(
        private ValidatorInterface $validator,
        #[Autowire(env: 'int:LOGHUB_MAX_MESSAGE_BYTES')] private int $maxMessageBytes,
        // ponytail: one limit reused for both `attributes` and `resource` — spec 04.6 only names "attributes size".
        #[Autowire(env: 'int:LOGHUB_MAX_ATTRIBUTES_BYTES')] private int $maxAttributesBytes,
        #[Autowire(env: 'int:LOGHUB_MAX_STACKTRACE_BYTES')] private int $maxStacktraceBytes,
        #[Autowire(env: 'int:LOGHUB_MAX_NESTING_DEPTH')] private int $maxNestingDepth,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function map(array $raw, ProjectId $projectId): LogRecord
    {
        foreach (self::OPTIONAL_KEYS as $key) {
            if (\array_key_exists($key, $raw) && null === $raw[$key]) {
                unset($raw[$key]);
            }
        }

        $violations = $this->validator->validate($raw, $this->structureConstraint());
        if (\count($violations) > 0) {
            throw new InvalidLogRecordException((string) $violations->get(0)->getMessage());
        }

        $message = (string) $raw['message'];
        if (\strlen($message) > $this->maxMessageBytes) {
            throw new InvalidLogRecordException(sprintf('message exceeds max size of %d bytes', $this->maxMessageBytes));
        }

        $attributes = \is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [];
        $this->assertWithinLimits($attributes, 'attributes');

        $resource = $raw['resource'];
        $this->assertWithinLimits($resource, 'resource');

        $exception = \is_array($raw['exception'] ?? null) ? $raw['exception'] : null;
        if (null !== $exception && isset($exception['stacktrace']) && \strlen((string) $exception['stacktrace']) > $this->maxStacktraceBytes) {
            throw new InvalidLogRecordException(sprintf('exception.stacktrace exceeds max size of %d bytes', $this->maxStacktraceBytes));
        }

        return new LogRecord(
            id: LogId::generate(),
            projectId: $projectId,
            timestamp: $this->parseTimestamp((string) $raw['timestamp']),
            severity: $this->mapSeverity($raw['severity']),
            message: $message,
            channel: isset($raw['channel']) ? (string) $raw['channel'] : null,
            resource: new ResourceAttributes($resource),
            attributes: new LogAttributes($attributes),
            trace: $this->mapTrace($raw),
            exception: $this->mapException($exception),
        );
    }

    private function structureConstraint(): Assert\Collection
    {
        return new Assert\Collection(
            fields: [
                'timestamp' => [new Assert\NotBlank(), new Assert\Type('string')],
                'severity' => new Assert\Collection(
                    fields: [
                        'text' => [new Assert\NotBlank(), new Assert\Type('string')],
                        'number' => [new Assert\NotNull(), new Assert\Type('int')],
                    ],
                    allowExtraFields: true,
                ),
                'message' => [new Assert\NotBlank(), new Assert\Type('string')],
                'resource' => new Assert\Collection(
                    fields: [
                        'service.name' => [new Assert\NotBlank(), new Assert\Type('string')],
                    ],
                    allowExtraFields: true,
                ),
                'channel' => new Assert\Optional([new Assert\Type('string')]),
                'attributes' => new Assert\Optional([new Assert\Type('array')]),
                'trace_id' => new Assert\Optional(),
                'span_id' => new Assert\Optional(),
                'exception' => new Assert\Optional(),
                // Never trusted (spec 04.2): allowed through validation, but ignored by the mapper below.
                'project_id' => new Assert\Optional(),
            ],
            allowExtraFields: true,
        );
    }

    private function parseTimestamp(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new InvalidLogRecordException('timestamp is not a valid date-time');
        }
    }

    /**
     * @param array<string, mixed> $severity
     */
    private function mapSeverity(array $severity): Severity
    {
        try {
            return new Severity((string) $severity['text'], (int) $severity['number']);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidLogRecordException($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapTrace(array $raw): ?TraceContext
    {
        $traceId = $raw['trace_id'] ?? null;
        $spanId = $raw['span_id'] ?? null;

        if (null === $traceId || null === $spanId) {
            return null;
        }

        return new TraceContext((string) $traceId, (string) $spanId);
    }

    /**
     * @param array<string, mixed>|null $exception
     */
    private function mapException(?array $exception): ?ExceptionData
    {
        if (null === $exception || !isset($exception['type'], $exception['message'])) {
            return null;
        }

        return new ExceptionData(
            (string) $exception['type'],
            (string) $exception['message'],
            isset($exception['stacktrace']) ? (string) $exception['stacktrace'] : null,
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function assertWithinLimits(array $values, string $field): void
    {
        $encoded = json_encode($values, JSON_THROW_ON_ERROR);
        if (\strlen($encoded) > $this->maxAttributesBytes) {
            throw new InvalidLogRecordException(sprintf('%s exceeds max size of %d bytes', $field, $this->maxAttributesBytes));
        }

        if ($this->depthOf($values) > $this->maxNestingDepth) {
            throw new InvalidLogRecordException(sprintf('%s exceeds max nesting depth of %d', $field, $this->maxNestingDepth));
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function depthOf(array $value): int
    {
        $max = 0;
        foreach ($value as $item) {
            if (\is_array($item)) {
                $max = max($max, $this->depthOf($item));
            }
        }

        return $max + 1;
    }
}
