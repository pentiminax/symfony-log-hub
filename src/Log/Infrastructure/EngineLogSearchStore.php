<?php

declare(strict_types=1);

namespace App\Log\Infrastructure;

use App\Log\Application\DateRange;
use App\Log\Application\Filter;
use App\Log\Application\LogCursor;
use App\Log\Application\LogSearchQuery;
use App\Log\Application\LogSearchResult;
use App\Log\Application\LogSearchStoreInterface;
use App\Log\Domain\ExceptionData;
use App\Log\Domain\LogAttributes;
use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\LogRecordCollection;
use App\Log\Domain\ProjectId;
use App\Log\Domain\ResourceAttributes;
use App\Log\Domain\Severity;
use App\Log\Domain\TraceContext;
use LogHub\EngineBundle\Client\EngineClient;
use LogHub\EngineBundle\Exception\InvalidEngineRequestException;

/**
 * Search over the LogHub engine. An invalid query (unknown field, bad cursor, ...)
 * surfaces as \InvalidArgumentException, like every other store; an unreachable
 * engine surfaces as the bundle's EngineUnavailableException, deliberately uncaught.
 */
final readonly class EngineLogSearchStore implements LogSearchStoreInterface
{
    /** Microsecond precision matters: the keyset cursor compares on it. */
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(private EngineClient $engine)
    {
    }

    public function search(LogSearchQuery $query): LogSearchResult
    {
        $body = $this->body($query) + [
            'sort' => ['field' => $query->sort->field, 'direction' => $query->sort->direction->value],
            'limit' => $query->pagination->limit,
        ];

        if (null !== $cursor = $query->pagination->cursor) {
            $body['cursor'] = ['timestamp' => $cursor->timestamp->format(self::TIMESTAMP_FORMAT), 'id' => (string) $cursor->id];
        }

        $response = $this->call(fn (): array => $this->engine->searchLogs((string) $query->projectId, $body));
        $next = $response['next_cursor'] ?? null;

        return new LogSearchResult(
            new LogRecordCollection(...array_map($this->hydrate(...), $response['records'])),
            null === $next ? null : new LogCursor(new \DateTimeImmutable($next['timestamp']), LogId::fromString($next['id'])),
            (bool) $response['has_more'],
        );
    }

    public function find(ProjectId $projectId, LogId $id): ?LogRecord
    {
        $data = $this->call(fn (): ?array => $this->engine->findLog((string) $projectId, (string) $id));

        return null === $data ? null : $this->hydrate($data);
    }

    public function countUpTo(LogSearchQuery $query, int $max = 10_000): int
    {
        return $this->call(fn (): int => $this->engine->countLogs((string) $query->projectId, $this->body($query) + ['max' => $max]));
    }

    public function distinctValues(ProjectId $projectId, string $field, DateRange $range): array
    {
        return $this->call(fn (): array => $this->engine->logFacets(
            (string) $projectId,
            $field,
            $range->from?->format(self::TIMESTAMP_FORMAT),
            $range->to?->format(self::TIMESTAMP_FORMAT),
        ));
    }

    /**
     * @return array<string, mixed> the part of the engine request shared by search and count
     */
    private function body(LogSearchQuery $query): array
    {
        return [
            'range' => [
                'from' => $query->range->from?->format(self::TIMESTAMP_FORMAT),
                'to' => $query->range->to?->format(self::TIMESTAMP_FORMAT),
            ],
            'search' => $query->search,
            'filters' => array_map(
                static fn (Filter $f): array => ['field' => $f->field, 'operator' => $f->operator->value] + (null === $f->value ? [] : ['value' => $f->value]),
                $query->filters,
            ),
        ];
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function call(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (InvalidEngineRequestException $e) {
            throw new \InvalidArgumentException($e->getMessage(), previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrate(array $data): LogRecord
    {
        $trace = null;
        if (null !== ($data['trace_id'] ?? null) && null !== ($data['span_id'] ?? null)) {
            $trace = new TraceContext($data['trace_id'], $data['span_id']);
        }

        $exception = null;
        if (null !== ($data['exception'] ?? null)) {
            $exception = new ExceptionData($data['exception']['type'], $data['exception']['message'], $data['exception']['stacktrace'] ?? null);
        }

        return new LogRecord(
            id: LogId::fromString($data['id']),
            projectId: ProjectId::fromString($data['project_id']),
            timestamp: new \DateTimeImmutable($data['timestamp']),
            severity: new Severity($data['severity']['text'], (int) $data['severity']['number']),
            message: $data['message'],
            channel: $data['channel'] ?? null,
            resource: new ResourceAttributes($data['resource'] ?? []),
            attributes: new LogAttributes($data['attributes'] ?? []),
            trace: $trace,
            exception: $exception,
        );
    }
}
