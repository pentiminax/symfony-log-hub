<?php

declare(strict_types=1);

namespace App\Tests\Log\Infrastructure;

use App\Log\Application\DateRange;
use App\Log\Application\Filter;
use App\Log\Application\FilterOperator;
use App\Log\Application\LogCursor;
use App\Log\Application\LogSearchQuery;
use App\Log\Application\LogSort;
use App\Log\Application\Pagination;
use App\Log\Application\SortDirection;
use App\Log\Domain\LogId;
use App\Log\Domain\ProjectId;
use App\Log\Infrastructure\EngineLogSearchStore;
use LogHub\EngineBundle\Client\EngineClient;
use LogHub\EngineBundle\Exception\EngineUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EngineLogSearchStoreTest extends TestCase
{
    private const LOG_ID = '0199c0de-0000-7000-8000-000000000001';

    private ProjectId $projectId;
    /** @var list<array{string, string, string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->projectId = ProjectId::generate();
    }

    public function testSearchMapsTheQueryToTheEngineBody(): void
    {
        $cursor = new LogCursor(new \DateTimeImmutable('2026-09-05T10:00:01.123456+00:00'), LogId::fromString(self::LOG_ID));
        $query = new LogSearchQuery(
            $this->projectId,
            new DateRange(new \DateTimeImmutable('2026-09-05T00:00:00+00:00')),
            'timeout',
            [
                new Filter('service.name', FilterOperator::EQUALS, 'billing'),
                new Filter('severity', FilterOperator::BETWEEN, [17, 20]),
                new Filter('trace.id', FilterOperator::EXISTS),
            ],
            new LogSort('timestamp', SortDirection::ASC),
            new Pagination(50, $cursor),
        );

        $this->store($this->json(['records' => [], 'next_cursor' => null, 'has_more' => false]))->search($query);

        [$method, $url, $body] = $this->requests[0];
        self::assertSame('POST', $method);
        self::assertSame("http://engine/v1/projects/{$this->projectId}/logs/search", $url);
        self::assertEquals([
            'range' => ['from' => '2026-09-05T00:00:00.000000+00:00', 'to' => null],
            'search' => 'timeout',
            'filters' => [
                ['field' => 'service.name', 'operator' => 'equals', 'value' => 'billing'],
                ['field' => 'severity', 'operator' => 'between', 'value' => [17, 20]],
                ['field' => 'trace.id', 'operator' => 'exists'],
            ],
            'sort' => ['field' => 'timestamp', 'direction' => 'asc'],
            'limit' => 50,
            'cursor' => ['timestamp' => '2026-09-05T10:00:01.123456+00:00', 'id' => self::LOG_ID],
        ], json_decode($body, true));
    }

    public function testSearchMapsTheResponseToDomainObjects(): void
    {
        $result = $this->store($this->json([
            'records' => [$this->log(), $this->log(['id' => '0199c0de-0000-7000-8000-000000000002', 'trace_id' => null, 'span_id' => null, 'exception' => null, 'channel' => null])],
            'next_cursor' => ['timestamp' => '2026-09-05T10:00:01.123456Z', 'id' => self::LOG_ID],
            'has_more' => true,
        ]))->search($this->query());

        [$first, $second] = $result->records->toArray();
        self::assertSame(self::LOG_ID, (string) $first->id);
        self::assertSame('2026-09-05T10:00:01.123456', $first->timestamp->format('Y-m-d\TH:i:s.u'));
        self::assertSame('ERROR', $first->severity->text);
        self::assertSame(17, $first->severity->number);
        self::assertSame('app', $first->channel);
        self::assertSame('billing', $first->resource->get('service.name'));
        self::assertSame(42, $first->attributes->get('payment_id'));
        self::assertSame('trace-abc', $first->trace?->traceId);
        self::assertSame('span-abc', $first->trace?->spanId);
        self::assertSame('RuntimeException', $first->exception?->type);
        self::assertSame('#0 {main}', $first->exception?->stacktrace);
        self::assertNull($second->trace);
        self::assertNull($second->exception);
        self::assertNull($second->channel);

        self::assertTrue($result->hasMore);
        self::assertSame('2026-09-05T10:00:01.123456', $result->nextCursor?->timestamp->format('Y-m-d\TH:i:s.u'));
        self::assertSame(self::LOG_ID, (string) $result->nextCursor?->id);
    }

    public function testTheLastPageHasNoCursor(): void
    {
        $result = $this->store($this->json(['records' => [], 'next_cursor' => null, 'has_more' => false]))->search($this->query());

        self::assertNull($result->nextCursor);
        self::assertFalse($result->hasMore);
    }

    public function testAnInvalidQueryBecomesAnInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown field "nope"');

        $this->store($this->json(['error' => 'invalid_request', 'message' => 'unknown field "nope"'], 422))->search($this->query());
    }

    public function testAnUnavailableEngineIsNotSwallowed(): void
    {
        $this->expectException(EngineUnavailableException::class);

        $this->store($this->json(['status' => 'unavailable'], 503))->search($this->query());
    }

    public function testFind(): void
    {
        $store = $this->store($this->json($this->log()), $this->json(['error' => 'not_found'], 404));

        self::assertSame(self::LOG_ID, (string) $store->find($this->projectId, LogId::fromString(self::LOG_ID))?->id);
        self::assertNull($store->find($this->projectId, LogId::fromString(self::LOG_ID)));
        self::assertSame('GET', $this->requests[0][0]);
        self::assertSame("http://engine/v1/projects/{$this->projectId}/logs/".self::LOG_ID, $this->requests[0][1]);
    }

    public function testCountUpToSendsTheMax(): void
    {
        self::assertSame(3, $this->store($this->json(['count' => 3]))->countUpTo($this->query(), 500));

        self::assertSame('http://engine/v1/projects/'.$this->projectId.'/logs/count', $this->requests[0][1]);
        self::assertSame(500, json_decode($this->requests[0][2], true)['max']);
    }

    public function testDistinctValues(): void
    {
        $values = $this->store($this->json(['values' => ['api', 'worker']]))
            ->distinctValues($this->projectId, 'service.name', new DateRange(new \DateTimeImmutable('2026-09-05T00:00:00+00:00')));

        self::assertSame(['api', 'worker'], $values);
        self::assertStringContainsString('/logs/facets/service.name?from=2026-09-05T00:00:00.000000%2B00:00', $this->requests[0][1]);
    }

    private function query(): LogSearchQuery
    {
        return new LogSearchQuery($this->projectId, new DateRange(), null, [], new LogSort('timestamp'), new Pagination(50));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function log(array $overrides = []): array
    {
        return $overrides + [
            'id' => self::LOG_ID,
            'project_id' => (string) $this->projectId,
            'timestamp' => '2026-09-05T10:00:01.123456Z',
            'severity' => ['text' => 'ERROR', 'number' => 17],
            'message' => 'payment failed',
            'channel' => 'app',
            'resource' => ['service.name' => 'billing'],
            'attributes' => ['payment_id' => 42],
            'trace_id' => 'trace-abc',
            'span_id' => 'span-abc',
            'exception' => ['type' => 'RuntimeException', 'message' => 'boom', 'stacktrace' => '#0 {main}'],
        ];
    }

    /** @param array<mixed> $data */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $status]);
    }

    private function store(MockResponse ...$responses): EngineLogSearchStore
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = [$method, $url, $options['body'] ?? ''];

            return array_shift($responses);
        }, 'http://engine');

        return new EngineLogSearchStore(new EngineClient($http));
    }
}
