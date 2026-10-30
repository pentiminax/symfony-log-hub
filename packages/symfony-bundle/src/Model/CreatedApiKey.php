<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Model;

final readonly class CreatedApiKey
{
    /** @param string $key plaintext key: the engine returns it once and stores only its hash */
    public function __construct(
        public ApiKey $apiKey,
        public string $key,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(ApiKey::fromArray($data), $data['key']);
    }
}
