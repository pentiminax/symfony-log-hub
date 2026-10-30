<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'loghub:key:revoke', description: 'Revokes an API key of a project')]
final class RevokeApiKeyCommand extends AbstractClientCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project id (UUID)')] string $projectId,
        #[Argument(description: 'Key id (UUID)')] string $keyId,
    ): int {
        $this->assertConfigured();
        $this->client->revokeApiKey($projectId, $keyId);

        $io->success(sprintf('API key "%s" revoked.', $keyId));

        return Command::SUCCESS;
    }
}
