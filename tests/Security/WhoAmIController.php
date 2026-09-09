<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AuthenticatedProject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-only diagnostic route: exists solely to exercise the `/api/v1/*` firewall end to
 * end in functional tests, registered as a service only `when@test` (see services.yaml).
 * Not a production endpoint.
 */
final class WhoAmIController extends AbstractController
{
    #[Route('/api/v1/_test/whoami', name: 'test_whoami', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var AuthenticatedProject $project */
        $project = $this->getUser();

        return new JsonResponse(['projectId' => (string) $project->projectId]);
    }
}
