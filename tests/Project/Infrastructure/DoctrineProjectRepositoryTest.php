<?php

declare(strict_types=1);

namespace App\Tests\Project\Infrastructure;

use App\Log\Domain\ProjectId;
use App\Project\Domain\Project;
use App\Project\Infrastructure\DoctrineProjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineProjectRepositoryTest extends KernelTestCase
{
    private DoctrineProjectRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = new DoctrineProjectRepository(self::getContainer()->get(EntityManagerInterface::class));
        self::getContainer()->get(Connection::class)->executeStatement('TRUNCATE TABLE project CASCADE');
    }

    public function testSaveAndFindByIdRoundTrip(): void
    {
        $project = Project::create('checkout', retentionDays: 45);

        $this->repository->save($project);

        $found = $this->repository->findById($project->id);

        self::assertNotNull($found);
        self::assertTrue($project->id->equals($found->id));
        self::assertSame('checkout', $found->name);
        self::assertSame(45, $found->retentionDays);
    }

    public function testFindByIdReturnsNullForAnUnknownProject(): void
    {
        self::assertNull($this->repository->findById(ProjectId::generate()));
    }

    public function testFindAllReturnsEveryProjectSortedByName(): void
    {
        $this->repository->save(Project::create('shipping'));
        $this->repository->save(Project::create('billing'));

        $names = array_map(static fn (Project $project): string => $project->name, $this->repository->findAll());

        self::assertSame(['billing', 'shipping'], $names);
    }

    public function testSaveUpdatesAnExistingProject(): void
    {
        $project = Project::create('checkout');
        $this->repository->save($project);

        $renamed = $project->rename('checkout-v2')->withRetentionDays(14);
        $this->repository->save($renamed);

        $found = $this->repository->findById($project->id);

        self::assertSame('checkout-v2', $found->name);
        self::assertSame(14, $found->retentionDays);
    }
}
