<?php

namespace App\Tracing;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * W3C trace context (traceparent/tracestate) of the dispatching span, carried to the worker.
 */
final readonly class TraceContextStamp implements StampInterface
{
    /**
     * @param array<string, string> $carrier
     */
    public function __construct(public array $carrier)
    {
    }
}
