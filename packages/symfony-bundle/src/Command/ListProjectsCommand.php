<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'loghub:project:list', description: 'Lists the projects of the LogHub engine')]
final class ListProjectsCommand extends AbstractClientCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        $this->assertConfigured();
        $io->table(
            ['Id', 'Name', 'Created at'],
            array_map(static fn ($p): array => [$p->id, $p->name, $p->createdAt->format(\DATE_ATOM)], $this->client->listProjects()),
        );

        return Command::SUCCESS;
    }
}
