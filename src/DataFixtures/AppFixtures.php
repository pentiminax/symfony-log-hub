<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Security\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use LogHub\EngineBundle\Client\EngineClient;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly EngineClient $engine,
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
        $manager->flush();

        // Requires the engine to be running.
        $project = $this->engine->createProject('Demo');
        $created = $this->engine->createApiKey($project->id, 'demo-app');

        fwrite(STDOUT, sprintf("Project \"Demo\" created (id: %s)\n", $project->id));
        fwrite(STDOUT, sprintf("API key: %s\n", $created->key));
    }
}
