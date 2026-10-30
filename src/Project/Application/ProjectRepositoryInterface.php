<?php

declare(strict_types=1);

namespace App\Project\Application;

use App\Log\Domain\ProjectId;
use App\Project\Domain\Project;

interface ProjectRepositoryInterface
{
    /**
     * The storage assigns the id.
     */
    public function create(string $name): Project;

    public function findById(ProjectId $id): ?Project;

    /**
     * @return list<Project> ordered by name
     */
    public function findAll(): array;
}
