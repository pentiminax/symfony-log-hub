<?php

declare(strict_types=1);

namespace App\Trace\Infrastructure;

use App\Log\Application\Filter;
use App\Log\Domain\ProjectId;
use App\Trace\Application\TraceCursor;
use App\Trace\Application\TraceSearchQuery;
use App\Trace\Application\TraceSearchResult;
use App\Trace\Application\TraceSearchStoreInterface;
use App\Trace\Domain\Span;
use App\Trace\Domain\Trace;
use App\Trace\Domain\TraceId;
use App\Trace\Domain\TraceSummary;
use LogHub\EngineBundle\Client\EngineClient;
use LogHub\EngineBundle\Exception\InvalidEngineRequestException;

/**
 * Like EngineLogSearchStore: an invalid query surfaces as \InvalidArgumentException, an
 * unreachable engine as the bundle's EngineUnavailableException, deliberately uncaught.
 */
final readonly class EngineTraceSearchStore implements TraceSearchStoreInterface
{
    /** Microsecond precision matters: the keyset cursor compares on it. */
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(private EngineClient $engine)
    {
    }

    public function search(TraceSearchQuery $query): TraceSearchResult
    {
        $body = [
            'range' => [
                'from' => $query->range->from?->format(self::TIMESTAMP_FORMAT),
                'to' => $query->range->to?->format(self::TIMESTAMP_FORMAT),
            ],
            'filters' => array_map(
                static fn (Filter $f): array => ['field' => $f->field, 'operator' => $f->operator->value] + (null === $f->value ? [] : ['value' => $f->value]),
                $query->filters,
            ),
            'limit' => $query->limit,
        ];

        if (null !== $cursor = $query->cursor) {
            $body['cursor'] = ['start_time' => $cursor->startTime->format(self::TIMESTAMP_FORMAT), 'trace_id' => (string) $cursor->traceId];
        }

        $response = $this->call(fn (): array => $this->engine->searchTraces((string) $query->projectId, $body));
        $next = $response['next_cursor'] ?? null;

        return new TraceSearchResult(
            array_map(static fn (array $t): TraceSummary => new TraceSummary(
                TraceId::fromString($t['trace_id']),
                $t['root']['name'] ?? null,
                $t['root']['service_name'] ?? null,
                new \DateTimeImmutable($t['start_time']),
                (int) $t['duration_ns'],
                (int) $t['span_count'],
                (int) $t['error_count'],
                $t['services'],
            ), $response['traces']),
            null === $next ? null : new TraceCursor(new \DateTimeImmutable($next['start_time']), TraceId::fromString($next['trace_id'])),
            (bool) $response['has_more'],
        );
    }

    public function find(ProjectId $projectId, TraceId $id): ?Trace
    {
        $data = $this->call(fn (): ?array => $this->engine->getTrace((string) $projectId, (string) $id));

        if (null === $data) {
            return null;
        }

        return new Trace($id, array_map(static fn (array $s): Span => new Span(
            $s['span_id'],
            $s['parent_span_id'] ?? null,
            $s['name'],
            $s['kind'],
            new \DateTimeImmutable($s['start_time']),
            (int) $s['duration_ns'],
            $s['status']['code'],
            $s['status']['message'] ?? null,
            $s['service_name'],
            $s['attributes'] ?? [],
            $s['resource'] ?? [],
            $s['events'] ?? [],
        ), $data['spans']));
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
}
