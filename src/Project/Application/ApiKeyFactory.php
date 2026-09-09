<?php

declare(strict_types=1);

namespace App\Project\Application;

use App\Log\Domain\ProjectId;
use App\Project\Domain\ApiKey;
use App\Project\Domain\ApiKeyId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ApiKeyFactory
{
    public function __construct(
        #[Autowire(env: 'APP_SECRET')] private string $pepper,
    ) {
    }

    /**
     * Generates a new secret for a project. Also the way to rotate a key: generate a
     * replacement, save it, then revoke the previous ApiKey (see ApiKey::revoke()).
     */
    public function generate(ProjectId $projectId, string $label): GeneratedApiKey
    {
        $plaintext = 'lh_'.bin2hex(random_bytes(32));

        $apiKey = ApiKey::issue(ApiKeyId::generate(), $projectId, ApiKey::hash($plaintext, $this->pepper), $label);

        return new GeneratedApiKey($plaintext, $apiKey);
    }
}
