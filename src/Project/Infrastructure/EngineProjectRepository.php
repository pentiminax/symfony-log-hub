<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Log\Domain\ProjectId;
use App\Project\Application\ProjectRepositoryInterface;
use App\Project\Domain\Project;
use LogHub\EngineBundle\Client\EngineClient;
use LogHub\EngineBundle\Model\Project as EngineProject;

final readonly class EngineProjectRepository implements ProjectRepositoryInterface
{
    public function __construct(private EngineClient $engine)
    {
    }

    public function create(string $name): Project
    {
        return $this->toDomain($this->engine->createProject($name));
    }

    public function findById(ProjectId $id): ?Project
    {
        $project = $this->engine->getProject((string) $id);

        return null === $project ? null : $this->toDomain($project);
    }

    public function findAll(): array
    {
        $projects = array_map($this->toDomain(...), $this->engine->listProjects());
        usort($projects, static fn (Project $a, Project $b): int => strcasecmp($a->name, $b->name));

        return $projects;
    }

    private function toDomain(EngineProject $project): Project
    {
        return new Project(ProjectId::fromString($project->id), $project->name);
    }
}
