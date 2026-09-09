<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Log\Domain\ProjectId;
use App\Project\Application\ProjectRepositoryInterface;
use App\Project\Domain\Project;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineProjectRepository implements ProjectRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Project $project): void
    {
        $entity = $this->entityManager->find(ProjectEntity::class, (string) $project->id) ?? ProjectEntity::fromDomain($project);

        $entity->name = $project->name;
        $entity->retentionDays = $project->retentionDays;

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findById(ProjectId $id): ?Project
    {
        return $this->entityManager->find(ProjectEntity::class, (string) $id)?->toDomain();
    }
}
