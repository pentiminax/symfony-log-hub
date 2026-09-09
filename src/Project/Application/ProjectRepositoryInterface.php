<?php

declare(strict_types=1);

namespace App\Project\Application;

use App\Log\Domain\ProjectId;
use App\Project\Domain\Project;

interface ProjectRepositoryInterface
{
    public function save(Project $project): void;

    public function findById(ProjectId $id): ?Project;

    /**
     * @return list<Project>
     */
    public function findAll(): array;
}
