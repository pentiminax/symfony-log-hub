<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Log\Domain\ProjectId;
use App\Project\Domain\ApiKey;
use App\Project\Domain\ApiKeyId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'api_key')]
#[ORM\UniqueConstraint(name: 'uniq_api_key_hashed_key', columns: ['hashed_key'])]
#[ORM\Index(name: 'idx_api_key_project_id', columns: ['project_id'])]
class ApiKeyEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $id;

    #[ORM\Column(name: 'project_id', type: 'guid')]
    public string $projectId;

    #[ORM\Column(name: 'hashed_key', length: 64)]
    public string $hashedKey;

    #[ORM\Column(length: 255)]
    public string $label;

    #[ORM\Column(name: 'created_at')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    public static function fromDomain(ApiKey $apiKey): self
    {
        $entity = new self();
        $entity->id = (string) $apiKey->id;
        $entity->projectId = (string) $apiKey->projectId;
        $entity->hashedKey = $apiKey->hashedKey;
        $entity->label = $apiKey->label;
        $entity->createdAt = $apiKey->createdAt;
        $entity->revokedAt = $apiKey->revokedAt;

        return $entity;
    }

    public function toDomain(): ApiKey
    {
        return new ApiKey(
            ApiKeyId::fromString($this->id),
            ProjectId::fromString($this->projectId),
            $this->hashedKey,
            $this->label,
            $this->createdAt,
            $this->revokedAt,
        );
    }
}
