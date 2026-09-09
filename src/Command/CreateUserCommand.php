<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The only way to create a LogHub account: there is no public registration and no
 * user management screen (spec 08-security-retention.md §8.1).
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Creates a LogHub user account',
)]
final class CreateUserCommand
{
    private const int MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Email address used to sign in')]
        string $email,
        #[Option(description: 'Password to set; one is generated and shown once when omitted')]
        ?string $password = null,
    ): int {
        $email = strtolower(trim($email));

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('"%s" is not a valid email address.', $email));

            return Command::INVALID;
        }

        if (null !== $password && \strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Password must be at least %d characters.', self::MIN_PASSWORD_LENGTH));

            return Command::INVALID;
        }

        $generated = null === $password;
        $password ??= bin2hex(random_bytes(12));

        $user = new User($email);
        $user->password = $this->passwordHasher->hashPassword($user, $password);

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The unique index is the guard, so there is no read-then-write race to lose.
            $io->error(sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $io->success(sprintf('User "%s" created.', $email));

        if ($generated) {
            $io->writeln(sprintf('Password: <info>%s</info>', $password));
            $io->comment('Shown once. Store it now.');
        }

        return Command::SUCCESS;
    }
}
