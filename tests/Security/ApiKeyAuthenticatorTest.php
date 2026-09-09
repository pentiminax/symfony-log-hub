<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Project\Application\ApiKeyFactory;
use App\Project\Domain\Project;
use App\Project\Infrastructure\DoctrineApiKeyRepository;
use App\Project\Infrastructure\DoctrineProjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiKeyAuthenticatorTest extends WebTestCase
{
    private const WHOAMI_URL = '/api/v1/_test/whoami';

    private KernelBrowser $client;
    private DoctrineApiKeyRepository $apiKeys;
    private ApiKeyFactory $factory;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $pepper = self::getContainer()->getParameter('kernel.secret');

        $this->apiKeys = new DoctrineApiKeyRepository($entityManager, $pepper);
        $this->factory = new ApiKeyFactory($pepper);
        self::getContainer()->get(Connection::class)->executeStatement('TRUNCATE TABLE project CASCADE');

        $this->project = Project::create('checkout');
        (new DoctrineProjectRepository($entityManager))->save($this->project);
    }

    public function testAValidActiveKeyResolvesTheOwningProject(): void
    {
        $generated = $this->factory->generate($this->project->id, 'default');
        $this->apiKeys->save($generated->apiKey);

        $this->client->request('GET', self::WHOAMI_URL, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$generated->plaintext,
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode(['projectId' => (string) $this->project->id]),
            $this->client->getResponse()->getContent(),
        );
    }

    public function testAMissingAuthorizationHeaderIsRejected(): void
    {
        $this->client->request('GET', self::WHOAMI_URL);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAGarbageTokenIsRejected(): void
    {
        $this->client->request('GET', self::WHOAMI_URL, server: [
            'HTTP_AUTHORIZATION' => 'Bearer lh_does-not-exist',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testARevokedKeyIsRejected(): void
    {
        $generated = $this->factory->generate($this->project->id, 'default');
        $this->apiKeys->save($generated->apiKey);
        $this->apiKeys->save($generated->apiKey->revoke());

        $this->client->request('GET', self::WHOAMI_URL, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$generated->plaintext,
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
