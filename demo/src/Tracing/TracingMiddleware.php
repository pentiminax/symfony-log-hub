<?php

namespace App\Tracing;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Dispatch: a PRODUCER span whose context travels in a TraceContextStamp.
 * Worker: a CONSUMER span, child of that context, so handling joins the dispatching request's trace.
 */
final class TracingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly TracerProviderInterface $tracerProvider,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = (new \ReflectionClass($envelope->getMessage()))->getShortName();
        $received = $envelope->last(ReceivedStamp::class);

        if (null === $received) {
            $span = $this->tracer->spanBuilder('send '.$message)->setSpanKind(SpanKind::KIND_PRODUCER)->startSpan();
            $scope = $span->activate();
            try {
                $carrier = [];
                TraceContextPropagator::getInstance()->inject($carrier);

                return $this->traced($span, fn () => $stack->next()->handle($envelope->with(new TraceContextStamp($carrier)), $stack));
            } finally {
                $scope->detach();
            }
        }

        $parent = TraceContextPropagator::getInstance()->extract($envelope->last(TraceContextStamp::class)?->carrier ?? []);
        $span = $this->tracer->spanBuilder('process '.$message)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.destination.name', $received->getTransportName())
            ->startSpan();
        $scope = $span->activate();
        try {
            return $this->traced($span, fn () => $stack->next()->handle($envelope, $stack));
        } finally {
            $scope->detach();
            // The worker is long-running: export each message's spans right away.
            $this->tracerProvider->forceFlush();
        }
    }

    /**
     * @param \Closure(): Envelope $next
     */
    private function traced(SpanInterface $span, \Closure $next): Envelope
    {
        try {
            return $next();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }
}
