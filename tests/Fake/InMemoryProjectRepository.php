<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Log\Domain\ProjectId;
use App\Project\Application\ProjectRepositoryInterface;
use App\Project\Domain\Project;

final class InMemoryProjectRepository implements ProjectRepositoryInterface
{
    /** @var array<string, Project> */
    private array $projects = [];

    public function create(string $name): Project
    {
        $project = new Project(ProjectId::generate(), $name);
        $this->projects[(string) $project->id] = $project;

        return $project;
    }

    public function findById(ProjectId $id): ?Project
    {
        return $this->projects[(string) $id] ?? null;
    }

    public function findAll(): array
    {
        $projects = array_values($this->projects);
        usort($projects, static fn (Project $a, Project $b): int => strcasecmp($a->name, $b->name));

        return $projects;
    }
}
