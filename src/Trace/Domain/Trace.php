<?php

declare(strict_types=1);

namespace App\Trace\Domain;

final readonly class Trace
{
    /**
     * @param list<Span> $spans non-empty, ordered by start
     */
    public function __construct(
        public TraceId $id,
        public array $spans,
    ) {
        if ([] === $spans) {
            throw new \InvalidArgumentException('A trace has at least one span.');
        }
    }

    public function startUs(): int
    {
        return min(array_map(static fn (Span $s): int => $s->startUs(), $this->spans));
    }

    public function durationUs(): int
    {
        return max(array_map(static fn (Span $s): int => $s->endUs(), $this->spans)) - $this->startUs();
    }

    /** Where the span's bar starts, as a percentage of the trace; clamped so a minimum-width bar stays inside. */
    public function offsetPercent(Span $span): float
    {
        $total = $this->durationUs();

        return 0 === $total ? 0.0 : min(100 - $this->widthPercent($span), ($span->startUs() - $this->startUs()) / $total * 100);
    }

    /** At least 0.4% so a tiny span is still a visible tick. */
    public function widthPercent(Span $span): float
    {
        $total = $this->durationUs();

        return 0 === $total ? 100.0 : max(0.4, intdiv($span->durationNs, 1000) / $total * 100);
    }

    /** A stable hue (0-359) per service, to colour its bars. */
    public function serviceHue(string $service): int
    {
        return crc32($service) % 360;
    }

    public function errorCount(): int
    {
        return \count(array_filter($this->spans, static fn (Span $s): bool => $s->isError()));
    }

    /**
     * Depth-first, children by start time. A span whose parent is missing from the
     * trace (not exported yet, sampled out) is attached at root level.
     *
     * @return list<array{span: Span, depth: int}>
     */
    public function tree(): array
    {
        $byId = [];
        foreach ($this->spans as $span) {
            $byId[$span->spanId] = $span;
        }

        $children = [];
        $roots = [];
        foreach ($this->spans as $span) {
            if (null !== $span->parentSpanId && isset($byId[$span->parentSpanId]) && $span->parentSpanId !== $span->spanId) {
                $children[$span->parentSpanId][] = $span;
            } else {
                $roots[] = $span;
            }
        }

        $rows = [];
        $seen = [];
        $walk = static function (Span $span, int $depth) use (&$walk, &$rows, &$seen, $children): void {
            if (isset($seen[$span->spanId])) {
                return;
            }
            $seen[$span->spanId] = true;
            $rows[] = ['span' => $span, 'depth' => $depth];
            foreach ($children[$span->spanId] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };

        foreach ($roots as $root) {
            $walk($root, 0);
        }
        // Parent cycles (malformed data) never reach a root: show them rather than lose them.
        foreach ($this->spans as $span) {
            $walk($span, 0);
        }

        return $rows;
    }
}
