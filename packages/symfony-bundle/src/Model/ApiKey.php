<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Model;

final readonly class ApiKey
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $label,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $revokedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['project_id'],
            $data['label'],
            new \DateTimeImmutable($data['created_at']),
            null === $data['revoked_at'] ? null : new \DateTimeImmutable($data['revoked_at']),
        );
    }
}
