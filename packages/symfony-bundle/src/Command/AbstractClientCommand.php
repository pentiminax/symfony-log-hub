<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Command;

use LogHub\SymfonyBundle\Client\LogHubClient;

abstract class AbstractClientCommand
{
    public function __construct(
        protected readonly LogHubClient $client,
        private readonly ?string $masterKey,
    ) {
    }

    protected function assertConfigured(): void
    {
        if (null === $this->masterKey || '' === $this->masterKey) {
            throw new \RuntimeException('This command needs the engine master key: set "loghub.master_key" (e.g. master_key: \'%env(default::LOGHUB_MASTER_KEY)%\').');
        }
    }
}
