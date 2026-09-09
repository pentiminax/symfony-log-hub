<?php

declare(strict_types=1);

namespace App\UI\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The log explorer is the product's home: "/" is the Discover screen, not a redirect.
 */
final class LogsController extends AbstractController
{
    #[Route('/', name: 'app_logs', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('logs/index.html.twig');
    }
}
