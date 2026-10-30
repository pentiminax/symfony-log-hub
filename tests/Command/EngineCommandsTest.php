<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CreateApiKeyCommand;
use App\Command\CreateProjectCommand;
use App\Project\Infrastructure\EngineProjectRepository;
use LogHub\EngineBundle\Client\EngineClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EngineCommandsTest extends TestCase
{
    private const ID = '0199c0de-0000-7000-8000-00000000000a';

    /** @var list<array{string, string, string}> */
    private array $requests = [];

    public function testCreateProjectPrintsTheEngineId(): void
    {
        $tester = $this->tester('app:project:create', $this->project(201));
        $tester->execute(['name' => 'Demo']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(self::ID, $tester->getDisplay());
        self::assertSame('{"name":"Demo"}', $this->requests[0][2]);
    }

    public function testCreateApiKeyPrintsTheKeyOnce(): void
    {
        $key = new MockResponse(json_encode([
            'id' => 'k-1', 'project_id' => self::ID, 'label' => 'demo-app',
            'created_at' => '2026-09-05T10:00:00Z', 'revoked_at' => null, 'key' => 'lh_secret',
        ]), ['http_code' => 201]);
        $tester = $this->tester('app:api-key:create', $this->project(), $key);
        $tester->execute(['project-id' => self::ID, 'label' => 'demo-app']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('lh_secret', $tester->getDisplay());
        self::assertSame('http://engine/v1/projects/'.self::ID.'/keys', $this->requests[1][1]);
        self::assertSame('{"label":"demo-app"}', $this->requests[1][2]);
    }

    public function testCreateApiKeyFailsForAnUnknownProject(): void
    {
        $tester = $this->tester('app:api-key:create', new MockResponse('{"error":"not_found"}', ['http_code' => 404]));

        self::assertSame(Command::FAILURE, $tester->execute(['project-id' => self::ID, 'label' => 'x']));
        self::assertCount(1, $this->requests);
    }

    public function testCreateApiKeyRejectsAMalformedProjectId(): void
    {
        $tester = $this->tester('app:api-key:create');

        self::assertSame(Command::INVALID, $tester->execute(['project-id' => 'nope', 'label' => 'x']));
    }

    private function project(int $status = 200): MockResponse
    {
        return new MockResponse(json_encode(['id' => self::ID, 'name' => 'Demo', 'created_at' => '2026-09-05T10:00:00Z']), ['http_code' => $status]);
    }

    private function tester(string $name, MockResponse ...$responses): CommandTester
    {
        $engine = new EngineClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = [$method, $url, $options['body'] ?? ''];

            return array_shift($responses);
        }, 'http://engine'));
        $projects = new EngineProjectRepository($engine);

        $application = new Application();
        $application->addCommand(new CreateProjectCommand($projects));
        $application->addCommand(new CreateApiKeyCommand($projects, $engine));

        return new CommandTester($application->find($name));
    }
}
