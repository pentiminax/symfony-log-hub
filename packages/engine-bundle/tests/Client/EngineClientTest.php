<?php

declare(strict_types=1);

namespace LogHub\EngineBundle\Tests\Client;

use LogHub\EngineBundle\Client\EngineClient;
use LogHub\EngineBundle\Exception\EngineException;
use LogHub\EngineBundle\Exception\EngineUnauthorizedException;
use LogHub\EngineBundle\Exception\EngineUnavailableException;
use LogHub\EngineBundle\Exception\InvalidEngineRequestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EngineClientTest extends TestCase
{
    private const PROJECT = ['id' => 'p-1', 'name' => 'Demo', 'created_at' => '2026-09-05T10:00:00Z'];
    private const KEY = ['id' => 'k-1', 'project_id' => 'p-1', 'label' => 'app', 'created_at' => '2026-09-05T10:00:00Z', 'revoked_at' => null];

    /** @var list<array{string, string, string|null}> method, url, body */
    private array $requests = [];

    public function testCreateProject(): void
    {
        $project = $this->client($this->json(self::PROJECT, 201))->createProject('Demo');

        self::assertSame('Demo', $project->name);
        self::assertSame('p-1', $project->id);
        self::assertSame('2026-09-05', $project->createdAt->format('Y-m-d'));
        $this->assertRequest(0, 'POST', 'http://engine/v1/projects', '{"name":"Demo"}');
    }

    public function testListProjects(): void
    {
        $projects = $this->client($this->json([self::PROJECT]))->listProjects();

        self::assertCount(1, $projects);
        self::assertSame('Demo', $projects[0]->name);
        $this->assertRequest(0, 'GET', 'http://engine/v1/projects');
    }

    public function testGetProjectReturnsNullOn404(): void
    {
        self::assertNull($this->client($this->json(['error' => 'not_found'], 404))->getProject('nope'));
        $this->assertRequest(0, 'GET', 'http://engine/v1/projects/nope');
    }

    public function testCreateApiKeyExposesThePlaintextOnce(): void
    {
        $created = $this->client($this->json([...self::KEY, 'key' => 'lh_abc'], 201))->createApiKey('p-1', 'app');

        self::assertSame('lh_abc', $created->key);
        self::assertSame('k-1', $created->apiKey->id);
        self::assertNull($created->apiKey->revokedAt);
        $this->assertRequest(0, 'POST', 'http://engine/v1/projects/p-1/keys', '{"label":"app"}');
    }

    public function testListAndRevokeApiKeys(): void
    {
        $revoked = [...self::KEY, 'revoked_at' => '2026-09-06T00:00:00Z'];
        $client = $this->client($this->json([self::KEY]), $this->json($revoked));

        self::assertCount(1, $client->listApiKeys('p-1'));
        $key = $client->revokeApiKey('p-1', 'k-1');

        self::assertNotNull($key->revokedAt);
        $this->assertRequest(0, 'GET', 'http://engine/v1/projects/p-1/keys');
        $this->assertRequest(1, 'POST', 'http://engine/v1/projects/p-1/keys/k-1/revoke');
    }

    public function testSearchLogsPostsTheBodyAndReturnsTheDecodedResponse(): void
    {
        $response = ['records' => [], 'next_cursor' => null, 'has_more' => false];
        $result = $this->client($this->json($response))->searchLogs('p-1', ['limit' => 5]);

        self::assertSame($response, $result);
        $this->assertRequest(0, 'POST', 'http://engine/v1/projects/p-1/logs/search', '{"limit":5}');
    }

    public function testCountLogs(): void
    {
        self::assertSame(7, $this->client($this->json(['count' => 7]))->countLogs('p-1', ['max' => 10]));
        $this->assertRequest(0, 'POST', 'http://engine/v1/projects/p-1/logs/count', '{"max":10}');
    }

    public function testLogFacetsEncodesThePlusOfAnOffset(): void
    {
        $values = $this->client($this->json(['values' => ['api']]))->logFacets('p-1', 'service.name', '2026-09-05T10:00:00+02:00', null);

        self::assertSame(['api'], $values);
        $this->assertRequest(0, 'GET', 'http://engine/v1/projects/p-1/logs/facets/service.name?from=2026-09-05T10:00:00%2B02:00');
    }

    public function testFindLogReturnsNullOn404(): void
    {
        $client = $this->client($this->json(['id' => 'l-1']), $this->json(['error' => 'not_found'], 404));

        self::assertSame(['id' => 'l-1'], $client->findLog('p-1', 'l-1'));
        self::assertNull($client->findLog('p-1', 'l-2'));
        $this->assertRequest(0, 'GET', 'http://engine/v1/projects/p-1/logs/l-1');
    }

    public function testSearchTraces(): void
    {
        $response = ['traces' => [], 'next_cursor' => null, 'has_more' => false];

        self::assertSame($response, $this->client($this->json($response))->searchTraces('p-1', ['limit' => 5]));
        $this->assertRequest(0, 'POST', 'http://engine/v1/projects/p-1/traces/search', '{"limit":5}');
    }

    public function testGetTraceReturnsNullOn404(): void
    {
        $client = $this->client($this->json(['trace_id' => 't-1', 'spans' => []]), $this->json(['error' => 'not_found'], 404));

        self::assertSame(['trace_id' => 't-1', 'spans' => []], $client->getTrace('p-1', 't-1'));
        self::assertNull($client->getTrace('p-1', 't-2'));
        $this->assertRequest(0, 'GET', 'http://engine/v1/projects/p-1/traces/t-1');
    }

    public function testHealth(): void
    {
        self::assertSame('ok', $this->client($this->json(['status' => 'ok']))->health()['status']);
    }

    public function testA422CarriesTheEngineMessage(): void
    {
        $this->expectException(InvalidEngineRequestException::class);
        $this->expectExceptionMessage('unknown field "nope"');

        $this->client($this->json(['error' => 'invalid_request', 'message' => 'unknown field "nope"'], 422))->searchLogs('p-1', []);
    }

    public function testA401IsUnauthorized(): void
    {
        $this->expectException(EngineUnauthorizedException::class);

        $this->client($this->json(['error' => 'unauthorized'], 401))->listProjects();
    }

    #[DataProvider('unavailable')]
    public function testServerErrorsAreUnavailable(int $status): void
    {
        $this->expectException(EngineUnavailableException::class);

        $this->client($this->json(['error' => 'storage'], $status))->listProjects();
    }

    /** @return iterable<string, array{int}> */
    public static function unavailable(): iterable
    {
        yield '503' => [503];
        yield '500' => [500];
    }

    public function testTransportErrorsAreUnavailable(): void
    {
        $this->expectException(EngineUnavailableException::class);

        $this->client(new MockResponse('', ['error' => 'connection refused']))->listProjects();
    }

    public function testAn404OnANonNullableCallIsNotSwallowed(): void
    {
        $this->expectException(EngineException::class);

        $this->client($this->json(['error' => 'not_found'], 404))->createApiKey('nope', 'x');
    }

    /** @param array<mixed> $data */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }

    private function client(MockResponse ...$responses): EngineClient
    {
        $queue = $responses;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->requests[] = [$method, $url, $options['body'] ?? null];

            return array_shift($queue);
        }, 'http://engine');

        return new EngineClient($http);
    }

    private function assertRequest(int $i, string $method, string $url, ?string $body = null): void
    {
        self::assertSame([$method, $url], \array_slice($this->requests[$i], 0, 2));
        if (null !== $body) {
            self::assertSame($body, $this->requests[$i][2]);
        }
    }
}
