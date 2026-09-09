<?php

declare(strict_types=1);

namespace App\Security;

use App\Log\Domain\ProjectId;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The "user" of the `/api/v1/*` firewall: not a human, but the project a valid
 * API key resolved to. Controllers reach the resolved identity via `$this->getUser()`.
 */
final readonly class AuthenticatedProject implements UserInterface
{
    public function __construct(
        public ProjectId $projectId,
    ) {
    }

    public function getRoles(): array
    {
        return ['ROLE_API_PROJECT'];
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->projectId;
    }
}
