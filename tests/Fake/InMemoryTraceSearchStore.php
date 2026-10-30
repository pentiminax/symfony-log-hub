<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Log\Domain\ProjectId;
use App\Trace\Application\TraceCursor;
use App\Trace\Application\TraceSearchQuery;
use App\Trace\Application\TraceSearchResult;
use App\Trace\Application\TraceSearchStoreInterface;
use App\Trace\Domain\Trace;
use App\Trace\Domain\TraceId;
use App\Trace\Domain\TraceSummary;

/**
 * Just enough of the engine's semantics for UI tests: project scoping, keyset paging by
 * trace start, and the `service.name` / `status` filters. Real semantics live in the engine.
 */
final class InMemoryTraceSearchStore implements TraceSearchStoreInterface
{
    /** @var list<array{ProjectId, Trace}> */
    private array $traces = [];

    public function add(ProjectId $projectId, Trace $trace): void
    {
        $this->traces[] = [$projectId, $trace];
    }

    public function search(TraceSearchQuery $query): TraceSearchResult
    {
        $rows = [];
        foreach ($this->traces as [$projectId, $trace]) {
            if ($projectId->equals($query->projectId) && $this->matches($trace, $query)) {
                $rows[] = $this->summarise($trace);
            }
        }
        usort($rows, static fn (TraceSummary $a, TraceSummary $b): int => $b->start <=> $a->start ?: strcmp((string) $b->id, (string) $a->id));

        if (null !== $c = $query->cursor) {
            $rows = array_values(array_filter($rows, static fn (TraceSummary $r): bool => $r->start < $c->startTime || ($r->start == $c->startTime && strcmp((string) $r->id, (string) $c->traceId) < 0)));
        }

        $hasMore = \count($rows) > $query->limit;
        $page = \array_slice($rows, 0, $query->limit);
        $last = end($page);

        return new TraceSearchResult($page, $hasMore && false !== $last ? new TraceCursor($last->start, $last->id) : null, $hasMore);
    }

    public function find(ProjectId $projectId, TraceId $id): ?Trace
    {
        foreach ($this->traces as [$pid, $trace]) {
            if ($pid->equals($projectId) && (string) $trace->id === (string) $id) {
                return $trace;
            }
        }

        return null;
    }

    private function matches(Trace $trace, TraceSearchQuery $query): bool
    {
        foreach ($trace->spans as $span) {
            if ((null !== $query->range->from && $span->start < $query->range->from) || (null !== $query->range->to && $span->start > $query->range->to)) {
                continue;
            }
            foreach ($query->filters as $f) {
                $actual = match ($f->field) {
                    'service.name' => $span->serviceName,
                    'status' => $span->status,
                    default => throw new \InvalidArgumentException(sprintf('Unknown filterable field "%s".', $f->field)),
                };
                if ($actual !== $f->value) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    private function summarise(Trace $trace): TraceSummary
    {
        $root = null;
        foreach ($trace->spans as $span) {
            if (null === $span->parentSpanId) {
                $root = $span;
                break;
            }
        }

        return new TraceSummary(
            $trace->id,
            $root?->name,
            $root?->serviceName,
            $trace->spans[0]->start,
            $trace->durationUs() * 1000,
            \count($trace->spans),
            $trace->errorCount(),
            array_values(array_unique(array_map(static fn ($s): string => $s->serviceName, $trace->spans))),
        );
    }
}
