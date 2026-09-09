<?php

declare(strict_types=1);

namespace App\Project\Application;

use App\Log\Domain\ProjectId;
use App\Project\Domain\ApiKey;
use App\Project\Domain\ApiKeyId;

interface ApiKeyRepositoryInterface
{
    public function save(ApiKey $apiKey): void;

    public function findById(ApiKeyId $id): ?ApiKey;

    public function findActiveByProjectId(ProjectId $projectId): ?ApiKey;

    /**
     * Resolves the active API key matching a bearer secret, e.g. the `Authorization` header
     * value an authenticator receives. Hashing the candidate is the store's concern (it owns
     * the pepper), so callers only ever handle the plaintext secret, never the hash.
     */
    public function resolveActiveByPlaintext(string $plaintext): ?ApiKey;
}
