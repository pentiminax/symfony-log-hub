<?php

declare(strict_types=1);

namespace App\UI\Controller;

use App\Security\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Renders the sign-in form. The POST never reaches this action - form_login
 * intercepts it - but the route must still accept POST, because RouterListener runs
 * before the firewall and would answer 405 first.
 */
final class LoginController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function __invoke(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_logs');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            // First run: a self-hosted admin who just installed has no account yet.
            'no_users_yet' => 0 === $this->entityManager->getRepository(User::class)->count([]),
        ]);
    }
}
