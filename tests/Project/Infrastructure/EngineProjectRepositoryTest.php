<?php

declare(strict_types=1);

namespace App\Tests\Project\Infrastructure;

use App\Log\Domain\ProjectId;
use App\Project\Infrastructure\EngineProjectRepository;
use LogHub\EngineBundle\Client\EngineClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EngineProjectRepositoryTest extends TestCase
{
    private const ID_A = '0199c0de-0000-7000-8000-00000000000a';
    private const ID_B = '0199c0de-0000-7000-8000-00000000000b';

    /** @var list<array{string, string}> */
    private array $requests = [];

    public function testCreateReturnsTheProjectWithTheEngineId(): void
    {
        $project = $this->repository($this->project(self::ID_A, 'Demo', 201))->create('Demo');

        self::assertSame(self::ID_A, (string) $project->id);
        self::assertSame('Demo', $project->name);
        self::assertSame(['POST', 'http://engine/v1/projects'], $this->requests[0]);
    }

    public function testFindAllIsOrderedByName(): void
    {
        $response = new MockResponse(json_encode([
            ['id' => self::ID_B, 'name' => 'zeta', 'created_at' => '2026-09-05T10:00:00Z'],
            ['id' => self::ID_A, 'name' => 'Alpha', 'created_at' => '2026-09-05T10:00:00Z'],
        ]));

        self::assertSame(['Alpha', 'zeta'], array_map(static fn ($p) => $p->name, $this->repository($response)->findAll()));
    }

    public function testFindByIdReturnsNullForAnUnknownProject(): void
    {
        $repository = $this->repository($this->project(self::ID_A, 'Demo'), new MockResponse('{"error":"not_found"}', ['http_code' => 404]));

        self::assertSame('Demo', $repository->findById(ProjectId::fromString(self::ID_A))?->name);
        self::assertNull($repository->findById(ProjectId::fromString(self::ID_B)));
        self::assertSame('http://engine/v1/projects/'.self::ID_A, $this->requests[0][1]);
    }

    private function project(string $id, string $name, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode(['id' => $id, 'name' => $name, 'created_at' => '2026-09-05T10:00:00Z']), ['http_code' => $status]);
    }

    private function repository(MockResponse ...$responses): EngineProjectRepository
    {
        $http = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requests[] = [$method, $url];

            return array_shift($responses);
        }, 'http://engine');

        return new EngineProjectRepository(new EngineClient($http));
    }
}
