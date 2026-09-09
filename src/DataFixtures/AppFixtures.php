<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Project\Application\ApiKeyFactory;
use App\Project\Domain\Project;
use App\Project\Infrastructure\ApiKeyEntity;
use App\Project\Infrastructure\ProjectEntity;
use App\Security\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly ApiKeyFactory $apiKeyFactory,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Dev and test only. Real installs get their first account from
        // `bin/console app:user:create`, which is the only supported way in.
        $user = new User('admin@loghub.test');
        $user->password = $this->passwordHasher->hashPassword($user, 'loghub-dev-password');
        $manager->persist($user);

        $project = Project::create('Demo');
        $manager->persist(ProjectEntity::fromDomain($project));

        $generatedApiKey = $this->apiKeyFactory->generate($project->id, 'demo-app');
        $manager->persist(ApiKeyEntity::fromDomain($generatedApiKey->apiKey));

        $manager->flush();

        fwrite(STDOUT, sprintf("Project \"Demo\" created (id: %s)\n", $project->id));
        fwrite(STDOUT, sprintf("API key: %s\n", $generatedApiKey->plaintext));
    }
}
