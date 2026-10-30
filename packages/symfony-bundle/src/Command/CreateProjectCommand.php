<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'loghub:project:create', description: 'Creates a project in the LogHub engine')]
final class CreateProjectCommand extends AbstractClientCommand
{
    public function __invoke(SymfonyStyle $io, #[Argument(description: 'Project name')] string $name): int
    {
        $this->assertConfigured();
        $project = $this->client->createProject($name);

        $io->success(sprintf('Project "%s" created.', $project->name));
        $io->writeln(sprintf('Id: <info>%s</info>', $project->id));

        return Command::SUCCESS;
    }
}
