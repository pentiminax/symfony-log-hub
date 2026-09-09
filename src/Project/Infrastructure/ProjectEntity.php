<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Log\Domain\ProjectId;
use App\Project\Domain\Project;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'project')]
class ProjectEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $id;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(name: 'retention_days', nullable: true)]
    public ?int $retentionDays = null;

    public static function fromDomain(Project $project): self
    {
        $entity = new self();
        $entity->id = (string) $project->id;
        $entity->name = $project->name;
        $entity->retentionDays = $project->retentionDays;

        return $entity;
    }

    public function toDomain(): Project
    {
        return new Project(ProjectId::fromString($this->id), $this->name, $this->retentionDays);
    }
}
