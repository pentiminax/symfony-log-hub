<?php

declare(strict_types=1);

namespace App\Project\Domain;

use App\Log\Domain\ProjectId;

final readonly class Project
{
    public function __construct(
        public ProjectId $id,
        public string $name,
    ) {
    }
}
