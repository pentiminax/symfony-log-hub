<?php

declare(strict_types=1);

namespace App\Command;

use App\Project\Application\ProjectRepositoryInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:project:create',
    description: 'Creates a project',
)]
final class CreateProjectCommand
{
    public function __construct(private readonly ProjectRepositoryInterface $projects)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project name')]
        string $name,
    ): int {
        $project = $this->projects->create($name);

        $io->success(sprintf('Project "%s" created.', $project->name));
        $io->writeln(sprintf('Id: <info>%s</info>', $project->id));

        return Command::SUCCESS;
    }
}
