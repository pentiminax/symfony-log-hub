<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\Tracing;

use LogHub\SymfonyBundle\DependencyInjection\LogHubExtension;
use LogHub\SymfonyBundle\Tracing\MessengerTracingListener;
use LogHub\SymfonyBundle\Tracing\RequestTracingSubscriber;
use LogHub\SymfonyBundle\Tracing\TraceContextStamp;
use LogHub\SymfonyBundle\Tracing\TracerProviderFactory;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

final class TracingTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $provider;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
    }

    private function load(array $tracing): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test']));
        (new LogHubExtension())->load([['url' => 'http://engine:8080', 'service' => ['name' => 'x']] + $tracing], $container);

        return $container;
    }

    public function testTracingIsDisabledByDefault(): void
    {
        self::assertFalse($this->load([])->hasDefinition(RequestTracingSubscriber::class));
    }

    public function testTracingServicesAreRegisteredWhenEnabled(): void
    {
        $container = $this->load(['tracing' => ['enabled' => true]]);

        self::assertTrue($container->hasDefinition(RequestTracingSubscriber::class));
        self::assertTrue($container->hasDefinition(MessengerTracingListener::class));
        self::assertTrue($container->hasDefinition(\OpenTelemetry\API\Trace\TracerInterface::class));
        self::assertSame(
            ['%loghub.url%', '%loghub.api_key%', '%loghub.service_name%', '%loghub.service_version%', '%loghub.environment%'],
            $container->getDefinition(\OpenTelemetry\SDK\Trace\TracerProviderInterface::class)->getArguments(),
        );
    }

    public function testEndpointIsDerivedFromUrl(): void
    {
        self::assertSame('http://engine:8080/v1/traces', TracerProviderFactory::endpoint('http://engine:8080/'));
        self::assertSame('http://engine:8080/v1/traces', TracerProviderFactory::endpoint('http://engine:8080'));
    }

    public function testEmptyApiKeyYieldsProviderWithoutExporter(): void
    {
        $provider = TracerProviderFactory::create('http://engine:8080', '', 'x', null, 'test');

        $provider->getTracer('t')->spanBuilder('s')->startSpan()->end();
        self::assertTrue($provider->forceFlush());
    }

    public function testRequestSubscriberCreatesAndEndsServerSpan(): void
    {
        $subscriber = new RequestTracingSubscriber($this->provider->getTracer('t'), $this->provider);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/demo/sync', 'POST');
        $request->attributes->set('_route', 'app_demo_sync');

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertTrue(Span::getCurrent()->getContext()->isValid());
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 200)));
        $subscriber->onTerminate();

        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('POST /demo/sync', $spans[0]->getName());
        self::assertSame(SpanKind::KIND_SERVER, $spans[0]->getKind());
        self::assertSame('app_demo_sync', $spans[0]->getAttributes()->get('http.route'));
        self::assertSame(200, $spans[0]->getAttributes()->get('http.response.status_code'));
    }

    public function testRequestSubscriberSkipsProfilerPaths(): void
    {
        $subscriber = new RequestTracingSubscriber($this->provider->getTracer('t'), $this->provider);
        $subscriber->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/_profiler/x'), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate();

        self::assertCount(0, $this->exporter->getSpans());
    }

    public function testMessengerContextIsPropagatedAndConsumerSpanIsParented(): void
    {
        $listener = new MessengerTracingListener($this->provider->getTracer('t'), $this->provider);
        $request = $this->provider->getTracer('t')->spanBuilder('POST /demo/async')->startSpan();
        $scope = $request->activate();

        $send = new SendMessageToTransportsEvent(new Envelope(new \stdClass()), []);
        $listener->onSend($send);
        $stamp = $send->getEnvelope()->last(TraceContextStamp::class);
        self::assertInstanceOf(TraceContextStamp::class, $stamp);

        // A retry must not overwrite the original context.
        $again = new SendMessageToTransportsEvent($send->getEnvelope(), []);
        $listener->onSend($again);
        self::assertSame([$stamp], $again->getEnvelope()->all(TraceContextStamp::class));

        $scope->detach();
        $request->end();

        $listener->onReceived(new WorkerMessageReceivedEvent($send->getEnvelope(), 'async'));
        // The consumer span is active while handlers (and their logs) run.
        $active = Span::getCurrent()->getContext();
        self::assertTrue($active->isValid());
        $listener->onWorkerRunning($this->running());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        [$root, $consumer] = $spans;
        self::assertSame('process stdClass', $consumer->getName());
        self::assertSame(SpanKind::KIND_CONSUMER, $consumer->getKind());
        self::assertSame($root->getTraceId(), $consumer->getTraceId());
        self::assertSame($root->getSpanId(), $consumer->getParentSpanId());
        self::assertSame($active->getSpanId(), $consumer->getSpanId());
        self::assertSame('async', $consumer->getAttributes()->get('messaging.destination.name'));
    }

    public function testFailedMessageRecordsException(): void
    {
        $listener = new MessengerTracingListener($this->provider->getTracer('t'), $this->provider);
        $envelope = new Envelope(new \stdClass());

        $listener->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onFailed(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('boom')));
        $listener->onWorkerRunning($this->running());

        $span = $this->exporter->getSpans()[0];
        self::assertSame('Error', $span->getStatus()->getCode());
        self::assertSame('exception', $span->getEvents()[0]->getName());
    }

    public function testSkippedMessageGetsNoSpan(): void
    {
        $listener = new MessengerTracingListener($this->provider->getTracer('t'), $this->provider);
        $event = new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async');
        $event->shouldHandle(false);

        $listener->onReceived($event);
        $listener->onWorkerRunning($this->running());

        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        self::assertCount(0, $this->exporter->getSpans());
    }

    public function testBatchedMessageWithoutHandledEventDoesNotLeakItsSpan(): void
    {
        $listener = new MessengerTracingListener($this->provider->getTracer('t'), $this->provider);
        $first = new Envelope(new \stdClass());

        // A batch handler acks later: no Handled/Failed event before the next message.
        $listener->onReceived(new WorkerMessageReceivedEvent($first, 'async'));
        $listener->onWorkerRunning($this->running());
        $listener->onReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));
        $listener->onFailed(new WorkerMessageFailedEvent($first, 'async', new \RuntimeException('boom')));
        $listener->onWorkerRunning($this->running());

        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertNotSame($spans[0]->getTraceId(), $spans[1]->getTraceId());
        // The late failure of the first message is not recorded on the second one.
        self::assertSame('Unset', $spans[1]->getStatus()->getCode());
    }

    public function testResetEndsARequestThatNeverTerminated(): void
    {
        $subscriber = new RequestTracingSubscriber($this->provider->getTracer('t'), $this->provider);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, Request::create('/a'), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->reset();
        $subscriber->onRequest(new RequestEvent($kernel, Request::create('/b'), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate();

        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        [$a, $b] = $this->exporter->getSpans();
        // Without the reset, /b would have become a child of /a.
        self::assertNotSame($a->getTraceId(), $b->getTraceId());
    }

    private function running(): WorkerRunningEvent
    {
        return new WorkerRunningEvent($this->createStub(Worker::class), false);
    }
}
