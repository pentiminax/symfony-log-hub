<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tracing;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Dispatch: the current trace context travels in a TraceContextStamp.
 * Worker: a CONSUMER span, child of that context, is active while the handlers run.
 */
final class MessengerTracingListener implements ResetInterface
{
    private ?object $message = null;
    private ?SpanInterface $span = null;
    private ?ScopeInterface $scope = null;

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly TracerProviderInterface $tracerProvider,
    ) {
    }

    #[AsEventListener(SendMessageToTransportsEvent::class)]
    public function onSend(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        // A retried message keeps the context of its original dispatch.
        if (null !== $envelope->last(TraceContextStamp::class)) {
            return;
        }

        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier);
        if ([] !== $carrier) {
            $event->setEnvelope($envelope->with(new TraceContextStamp($carrier)));
        }
    }

    // Last, so a listener that skips the message (shouldHandle(false)) runs first.
    #[AsEventListener(WorkerMessageReceivedEvent::class, priority: -4096)]
    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->reset();
        if (!$event->shouldHandle()) {
            return;
        }

        $envelope = $event->getEnvelope();
        $this->message = $envelope->getMessage();
        $parent = TraceContextPropagator::getInstance()->extract($envelope->last(TraceContextStamp::class)?->carrier ?? []);

        $this->span = $this->tracer->spanBuilder('process '.(new \ReflectionClass($envelope->getMessage()))->getShortName())
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.destination.name', $event->getReceiverName())
            ->startSpan();
        $this->scope = $this->span->activate();
    }

    #[AsEventListener(WorkerMessageFailedEvent::class, priority: -4096)]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        // A batch handler may fail an earlier message while another one's span is active.
        if ($event->getEnvelope()->getMessage() !== $this->message) {
            return;
        }
        $this->span?->recordException($event->getThrowable());
        $this->span?->setStatus(StatusCode::STATUS_ERROR, $event->getThrowable()->getMessage());
    }

    /**
     * Dispatched after every received message, even skipped or batched ones that get no Handled/Failed event yet.
     */
    #[AsEventListener(WorkerRunningEvent::class)]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (null === $this->span) {
            return;
        }
        $this->reset();
        // The worker is long-running: export each message's spans right away.
        $this->tracerProvider->forceFlush();
    }

    public function reset(): void
    {
        $this->scope?->detach();
        $this->span?->end();
        $this->scope = $this->span = $this->message = null;
    }
}
