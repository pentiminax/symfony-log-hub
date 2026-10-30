<?php

declare(strict_types=1);

namespace App\Command;

use App\Log\Domain\ProjectId;
use App\Project\Application\ProjectRepositoryInterface;
use LogHub\EngineBundle\Client\EngineClient;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api-key:create',
    description: 'Generates an API key for a project',
)]
final class CreateApiKeyCommand
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly EngineClient $engine,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Project id (UUID) to issue the key for')]
        string $projectId,
        #[Argument(description: 'Label identifying this key (e.g. its purpose or environment)')]
        string $label,
    ): int {
        try {
            $id = ProjectId::fromString($projectId);
        } catch (\InvalidArgumentException) {
            $io->error(sprintf('"%s" is not a valid project id.', $projectId));

            return Command::INVALID;
        }

        if (null === $this->projects->findById($id)) {
            $io->error(sprintf('No project found with id "%s".', $projectId));

            return Command::FAILURE;
        }

        $created = $this->engine->createApiKey((string) $id, $label);

        $io->success(sprintf('API key "%s" created for project "%s".', $label, $projectId));
        $io->writeln(sprintf('Key: <info>%s</info>', $created->key));
        $io->comment('Shown once. Store it now.');

        return Command::SUCCESS;
    }
}
