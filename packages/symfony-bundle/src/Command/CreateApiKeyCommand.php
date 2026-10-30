<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'loghub:key:create', description: 'Generates an API key for a project')]
final class CreateApiKeyCommand extends AbstractClientCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project id (UUID)')] string $projectId,
        #[Argument(description: 'Label identifying this key (purpose or environment)')] string $label,
    ): int {
        $this->assertConfigured();
        $created = $this->client->createApiKey($projectId, $label);

        $io->success(sprintf('API key "%s" created for project "%s".', $label, $projectId));
        $io->writeln(sprintf('Key: <info>%s</info>', $created->key));
        $io->comment('Shown once. Store it now.');

        return Command::SUCCESS;
    }
}
