<?php

declare(strict_types=1);

namespace App\Project\Domain;

use App\Log\Domain\ProjectId;

final readonly class ApiKey
{
    public function __construct(
        public ApiKeyId $id,
        public ProjectId $projectId,
        public string $hashedKey,
        public string $label,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $revokedAt = null,
    ) {
    }

    public static function issue(ApiKeyId $id, ProjectId $projectId, string $hashedKey, string $label): self
    {
        return new self($id, $projectId, $hashedKey, $label, new \DateTimeImmutable());
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    /**
     * Revocation is idempotent: revoking an already-revoked key keeps its original revocation time.
     */
    public function revoke(): self
    {
        return new self($this->id, $this->projectId, $this->hashedKey, $this->label, $this->createdAt, $this->revokedAt ?? new \DateTimeImmutable());
    }

    public function verify(string $plaintextCandidate, string $pepper): bool
    {
        return hash_equals($this->hashedKey, self::hash($plaintextCandidate, $pepper));
    }

    /**
     * Keyed, deterministic hash (not password_hash/bcrypt): the plaintext is already a
     * high-entropy random secret, and a deterministic hash lets the store look up the
     * owning project by hash equality, which a per-hash-salted algorithm cannot do.
     */
    public static function hash(string $plaintext, string $pepper): string
    {
        return hash_hmac('sha256', $plaintext, $pepper);
    }
}
