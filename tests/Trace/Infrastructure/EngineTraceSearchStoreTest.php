<?php

declare(strict_types=1);

namespace App\Tests\Trace\Infrastructure;

use App\Log\Application\DateRange;
use App\Log\Application\Filter;
use App\Log\Application\FilterOperator;
use App\Log\Domain\ProjectId;
use App\Trace\Application\TraceCursor;
use App\Trace\Application\TraceSearchQuery;
use App\Trace\Domain\TraceId;
use App\Trace\Infrastructure\EngineTraceSearchStore;
use LogHub\EngineBundle\Client\EngineClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EngineTraceSearchStoreTest extends TestCase
{
    private const string TRACE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ProjectId $projectId;
    /** @var list<array{string, string, ?string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->projectId = ProjectId::generate();
    }

    public function testSearchMapsTheQueryToTheEngineBody(): void
    {
        $query = new TraceSearchQuery(
            $this->projectId,
            new DateRange(new \DateTimeImmutable('2026-09-05T00:00:00+00:00')),
            [new Filter('service.name', FilterOperator::EQUALS, 'billing'), new Filter('status', FilterOperator::EXISTS)],
            25,
            new TraceCursor(new \DateTimeImmutable('2026-09-05T10:00:01.123456+00:00'), TraceId::fromString(self::TRACE)),
        );

        $this->store($this->json(['traces' => [], 'next_cursor' => null, 'has_more' => false]))->search($query);

        [$method, $url, $body] = $this->requests[0];
        self::assertSame('POST', $method);
        self::assertSame("http://engine/v1/projects/{$this->projectId}/traces/search", $url);
        self::assertEquals([
            'range' => ['from' => '2026-09-05T00:00:00.000000+00:00', 'to' => null],
            'filters' => [
                ['field' => 'service.name', 'operator' => 'equals', 'value' => 'billing'],
                ['field' => 'status', 'operator' => 'exists'],
            ],
            'limit' => 25,
            'cursor' => ['start_time' => '2026-09-05T10:00:01.123456+00:00', 'trace_id' => self::TRACE],
        ], json_decode((string) $body, true));
    }

    public function testSearchMapsTheResponse(): void
    {
        $result = $this->store($this->json([
            'traces' => [
                ['trace_id' => self::TRACE, 'root' => ['span_id' => 'bbbbbbbbbbbbbbbb', 'name' => 'GET /pay', 'service_name' => 'api'], 'start_time' => '2026-09-05T10:00:00.5Z', 'duration_ns' => 140_000_000, 'span_count' => 3, 'error_count' => 1, 'services' => ['api', 'billing']],
                ['trace_id' => str_repeat('c', 32), 'root' => null, 'start_time' => '2026-09-05T09:00:00Z', 'duration_ns' => 5, 'span_count' => 1, 'error_count' => 0, 'services' => ['web']],
            ],
            'next_cursor' => ['start_time' => '2026-09-05T09:00:00Z', 'trace_id' => str_repeat('c', 32)],
            'has_more' => true,
        ]))->search($this->query());

        [$first, $second] = $result->traces;
        self::assertSame(self::TRACE, (string) $first->id);
        self::assertSame('GET /pay', $first->rootName);
        self::assertSame('api', $first->rootService);
        self::assertSame(140_000_000, $first->durationNs);
        self::assertSame(3, $first->spanCount);
        self::assertSame(1, $first->errorCount);
        self::assertSame(['api', 'billing'], $first->services);
        self::assertNull($second->rootName);
        self::assertTrue($result->hasMore);
        self::assertSame(str_repeat('c', 32), (string) $result->nextCursor?->traceId);
    }

    public function testFindMapsSpans(): void
    {
        $store = $this->store(
            $this->json(['trace_id' => self::TRACE, 'spans' => [[
                'span_id' => 'bbbbbbbbbbbbbbbb', 'parent_span_id' => null, 'name' => 'GET /pay', 'kind' => 'server',
                'start_time' => '2026-09-05T10:00:00Z', 'duration_ns' => 100_000_000,
                'status' => ['code' => 'error', 'message' => 'boom'], 'service_name' => 'api',
                'attributes' => ['http.method' => 'GET'], 'resource' => ['service.name' => 'api'],
                'events' => [['name' => 'exception', 'timestamp' => null, 'attributes' => []]],
            ]]]),
            $this->json(['error' => 'not_found'], 404),
        );

        $trace = $store->find($this->projectId, TraceId::fromString(self::TRACE));
        self::assertSame(1, \count($trace?->spans ?? []));
        $span = $trace->spans[0];
        self::assertTrue($span->isError());
        self::assertSame('boom', $span->statusMessage);
        self::assertSame('GET', $span->attributes['http.method']);
        self::assertSame('exception', $span->events[0]['name']);
        self::assertSame("http://engine/v1/projects/{$this->projectId}/traces/".self::TRACE, $this->requests[0][1]);

        self::assertNull($store->find($this->projectId, TraceId::fromString(self::TRACE)));
    }

    public function testAnInvalidQueryBecomesAnInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown filterable field "nope"');

        $this->store($this->json(['error' => 'invalid_request', 'message' => 'Unknown filterable field "nope"'], 422))->search($this->query());
    }

    public function testATraceIdMustBe32LowercaseHex(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TraceId::fromString('not-a-trace');
    }

    private function query(): TraceSearchQuery
    {
        return new TraceSearchQuery($this->projectId, new DateRange(), [], 50);
    }

    /** @param array<mixed> $data */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }

    private function store(MockResponse ...$responses): EngineTraceSearchStore
    {
        $queue = $responses;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->requests[] = [$method, $url, $options['body'] ?? null];

            return array_shift($queue);
        }, 'http://engine');

        return new EngineTraceSearchStore(new EngineClient($http));
    }
}
