<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Model;

final readonly class Project
{
    public function __construct(
        public string $id,
        public string $name,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'], new \DateTimeImmutable($data['created_at']));
    }
}
