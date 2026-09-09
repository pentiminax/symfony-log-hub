<?php

declare(strict_types=1);

namespace App\Tests\Project\Infrastructure;

use App\Project\Application\ApiKeyFactory;
use App\Project\Domain\ApiKeyId;
use App\Project\Domain\Project;
use App\Project\Infrastructure\DoctrineApiKeyRepository;
use App\Project\Infrastructure\DoctrineProjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineApiKeyRepositoryTest extends KernelTestCase
{
    private DoctrineApiKeyRepository $repository;
    private ApiKeyFactory $factory;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $pepper = self::getContainer()->getParameter('kernel.secret');

        $this->repository = new DoctrineApiKeyRepository($entityManager, $pepper);
        $this->factory = new ApiKeyFactory($pepper);
        self::getContainer()->get(Connection::class)->executeStatement('TRUNCATE TABLE project CASCADE');

        $this->project = Project::create('checkout');
        (new DoctrineProjectRepository($entityManager))->save($this->project);
    }

    public function testSaveAndFindByIdRoundTrip(): void
    {
        $generated = $this->factory->generate($this->project->id, 'default');
        $this->repository->save($generated->apiKey);

        $found = $this->repository->findById($generated->apiKey->id);

        self::assertNotNull($found);
        self::assertTrue($generated->apiKey->id->equals($found->id));
        self::assertTrue($this->project->id->equals($found->projectId));
        self::assertSame('default', $found->label);
        self::assertFalse($found->isRevoked());
    }

    public function testResolveActiveByPlaintextFindsTheOwningProject(): void
    {
        $generated = $this->factory->generate($this->project->id, 'default');
        $this->repository->save($generated->apiKey);

        $resolved = $this->repository->resolveActiveByPlaintext($generated->plaintext);

        self::assertNotNull($resolved);
        self::assertTrue($this->project->id->equals($resolved->projectId));
    }

    public function testResolveActiveByPlaintextRejectsAWrongSecret(): void
    {
        $generated = $this->factory->generate($this->project->id, 'default');
        $this->repository->save($generated->apiKey);

        self::assertNull($this->repository->resolveActiveByPlaintext('lh_wrong-secret'));
    }

    public function testResolveActiveByPlaintextIgnoresARevokedKey(): void
    {
        $generated = $this->factory->generate($this->project->id, 'default');
        $this->repository->save($generated->apiKey);
        $this->repository->save($generated->apiKey->revoke());

        self::assertNull($this->repository->resolveActiveByPlaintext($generated->plaintext));
    }

    public function testFindActiveByProjectIdReturnsNullWhenNoneExists(): void
    {
        self::assertNull($this->repository->findActiveByProjectId($this->project->id));
    }

    public function testFindByIdReturnsNullForAnUnknownKey(): void
    {
        self::assertNull($this->repository->findById(ApiKeyId::generate()));
    }
}
