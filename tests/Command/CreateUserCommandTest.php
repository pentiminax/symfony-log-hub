<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Security\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(Connection::class)->executeStatement('TRUNCATE TABLE app_user CASCADE');

        $this->command = new CommandTester(
            (new Application(self::$kernel))->find('app:user:create'),
        );
    }

    public function testItCreatesAUserWithTheGivenPassword(): void
    {
        $this->command->execute(['email' => 'ops@example.com', '--password' => 'a-long-enough-password']);

        $this->command->assertCommandIsSuccessful();
        $user = $this->find('ops@example.com');
        self::assertNotNull($user);
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)
                ->isPasswordValid($user, 'a-long-enough-password'),
        );
    }

    /** The generated password is shown exactly once, so it had better be in the output. */
    public function testItGeneratesAndPrintsAPasswordWhenNoneIsGiven(): void
    {
        $this->command->execute(['email' => 'ops@example.com']);

        $this->command->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/Password: [0-9a-f]{24}/', $this->command->getDisplay());
    }

    public function testEmailsAreNormalisedSoTheyCannotBeDuplicatedByCase(): void
    {
        $this->command->execute(['email' => '  OPS@Example.COM ', '--password' => 'a-long-enough-password']);

        self::assertNotNull($this->find('ops@example.com'));
    }

    public function testASecondUserWithTheSameEmailFailsWithoutAnException(): void
    {
        $this->command->execute(['email' => 'ops@example.com', '--password' => 'a-long-enough-password']);
        $exitCode = $this->command->execute(['email' => 'ops@example.com', '--password' => 'a-long-enough-password']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('already exists', $this->command->getDisplay());
    }

    public function testAnInvalidEmailIsRefused(): void
    {
        $exitCode = $this->command->execute(['email' => 'not-an-email', '--password' => 'a-long-enough-password']);

        self::assertSame(2, $exitCode);
        self::assertNull($this->find('not-an-email'));
    }

    public function testAShortPasswordIsRefused(): void
    {
        $exitCode = $this->command->execute(['email' => 'ops@example.com', '--password' => 'short']);

        self::assertSame(2, $exitCode);
        self::assertNull($this->find('ops@example.com'));
    }

    private function find(string $email): ?User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
