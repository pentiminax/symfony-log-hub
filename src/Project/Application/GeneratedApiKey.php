<?php

declare(strict_types=1);

namespace App\Project\Application;

use App\Project\Domain\ApiKey;

/**
 * Carries the plaintext secret alongside the persisted key, for the one moment
 * (creation or rotation) it is shown to the caller. Never reconstructable afterwards.
 */
final readonly class GeneratedApiKey
{
    public function __construct(
        public string $plaintext,
        public ApiKey $apiKey,
    ) {
    }
}
