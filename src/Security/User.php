<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The human operator of the UI.
 *
 * Not split into Domain/Application/Infrastructure like the Log and Project modules:
 * EntityUserProvider::refreshUser() requires the session-stored object to be an
 * instance of the mapped entity, so a readonly domain object behind a wrapper would
 * rule out the `entity` provider entirely. There is no domain behaviour here to protect.
 *
 * ponytail: no roles column - spec 08.1 grants every user access to every project.
 * Per-project permissions arrive as a join table plus a voter, not as role strings.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $id;

    #[ORM\Column(length: 180)]
    public string $email;

    #[ORM\Column(length: 255)]
    public string $password = '';

    public function __construct(string $email)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->email = $email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }
}
