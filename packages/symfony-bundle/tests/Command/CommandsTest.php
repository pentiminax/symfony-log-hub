<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\Command;

use LogHub\SymfonyBundle\Client\LogHubClient;
use LogHub\SymfonyBundle\Command\CreateApiKeyCommand;
use LogHub\SymfonyBundle\Command\CreateProjectCommand;
use LogHub\SymfonyBundle\Command\ListProjectsCommand;
use LogHub\SymfonyBundle\Command\RevokeApiKeyCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CommandsTest extends TestCase
{
    private const PROJECT = ['id' => 'p-1', 'name' => 'Demo', 'created_at' => '2026-09-05T10:00:00Z'];
    private const KEY = ['id' => 'k-1', 'project_id' => 'p-1', 'label' => 'app', 'created_at' => '2026-09-05T10:00:00Z', 'revoked_at' => null];

    /** @var list<array{string, string}> */
    private array $requests = [];

    public function testCreateProject(): void
    {
        $tester = $this->tester('loghub:project:create', new CreateProjectCommand($this->client(self::PROJECT), 'mk'));
        $tester->execute(['name' => 'Demo']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('p-1', $tester->getDisplay());
        self::assertSame([['POST', 'http://engine/v1/projects']], $this->requests);
    }

    public function testListProjects(): void
    {
        $tester = $this->tester('loghub:project:list', new ListProjectsCommand($this->client([self::PROJECT]), 'mk'));
        $tester->execute([]);

        self::assertStringContainsString('Demo', $tester->getDisplay());
    }

    public function testCreateKeyPrintsThePlaintextKey(): void
    {
        $tester = $this->tester('loghub:key:create', new CreateApiKeyCommand($this->client([...self::KEY, 'key' => 'lh_abc']), 'mk'));
        $tester->execute(['project-id' => 'p-1', 'label' => 'app']);

        self::assertStringContainsString('lh_abc', $tester->getDisplay());
        self::assertSame([['POST', 'http://engine/v1/projects/p-1/keys']], $this->requests);
    }

    public function testRevokeKey(): void
    {
        $tester = $this->tester('loghub:key:revoke', new RevokeApiKeyCommand($this->client([...self::KEY, 'revoked_at' => '2026-09-06T10:00:00Z']), 'mk'));
        $tester->execute(['project-id' => 'p-1', 'key-id' => 'k-1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([['POST', 'http://engine/v1/projects/p-1/keys/k-1/revoke']], $this->requests);
    }

    public function testFailsClearlyWithoutMasterKey(): void
    {
        $tester = $this->tester('loghub:project:list', new ListProjectsCommand($this->client([]), null));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('loghub.master_key');
        $tester->execute([]);
    }

    /** @param array<mixed> $body */
    private function client(array $body): LogHubClient
    {
        return new LogHubClient(new MockHttpClient(function (string $method, string $url) use ($body): MockResponse {
            $this->requests[] = [$method, $url];

            return new MockResponse(json_encode($body), ['http_code' => 200]);
        }, 'http://engine'));
    }

    private function tester(string $name, object $command): CommandTester
    {
        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find($name));
    }
}
