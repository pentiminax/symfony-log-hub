<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Project\Application\ApiKeyFactory;
use App\Project\Domain\Project;
use App\Project\Infrastructure\ApiKeyEntity;
use App\Project\Infrastructure\ProjectEntity;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly ApiKeyFactory $apiKeyFactory,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $project = Project::create('Demo');
        $manager->persist(ProjectEntity::fromDomain($project));

        $generatedApiKey = $this->apiKeyFactory->generate($project->id, 'demo-app');
        $manager->persist(ApiKeyEntity::fromDomain($generatedApiKey->apiKey));

        $manager->flush();

        fwrite(STDOUT, sprintf("Project \"Demo\" created (id: %s)\n", $project->id));
        fwrite(STDOUT, sprintf("API key: %s\n", $generatedApiKey->plaintext));
    }
}
