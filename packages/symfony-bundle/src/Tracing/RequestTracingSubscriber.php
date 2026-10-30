<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One SERVER span per main request; spans started while it is active become its children.
 * Resettable: in worker runtimes (FrankenPHP, RoadRunner), kernel.reset clears a request that never reached terminate.
 */
final class RequestTracingSubscriber implements ResetInterface
{
    private ?SpanInterface $span = null;
    private ?ScopeInterface $scope = null;

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly TracerProviderInterface $tracerProvider,
    ) {
    }

    #[AsEventListener(KernelEvents::REQUEST, priority: 4096)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // Skip the profiler and web debug toolbar.
        if (!$event->isMainRequest() || str_starts_with($request->getPathInfo(), '/_')) {
            return;
        }

        $this->span = $this->tracer->spanBuilder($request->getMethod().' '.$request->getPathInfo())
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', $request->getMethod())
            ->setAttribute('url.path', $request->getPathInfo())
            ->startSpan();
        $this->scope = $this->span->activate();
    }

    #[AsEventListener(KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->span?->recordException($event->getThrowable());
        }
    }

    #[AsEventListener(KernelEvents::RESPONSE, priority: -4096)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->span) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();
        $this->span->setAttribute('http.response.status_code', $status);
        $this->span->setAttribute('http.route', $event->getRequest()->attributes->get('_route'));
        if ($status >= 500) {
            $this->span->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function onTerminate(): void
    {
        $this->reset();
        $this->tracerProvider->forceFlush();
    }

    public function reset(): void
    {
        $this->scope?->detach();
        $this->span?->end();
        $this->scope = $this->span = null;
    }
}
