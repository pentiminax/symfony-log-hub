<?php

namespace App\Controller;

use App\Message\TestLogMessage;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class DemoLogController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/demo', name: 'app_demo', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('demo/index.html.twig');
    }

    #[Route('/demo/sync', name: 'app_demo_sync', methods: ['POST'])]
    public function sync(): RedirectResponse
    {
        $this->logger->info('Sync test log', ['source' => 'sync']);

        $this->addFlash('success', 'Sync log emitted.');

        return $this->redirectToRoute('app_demo');
    }

    #[Route('/demo/async', name: 'app_demo_async', methods: ['POST'])]
    public function async(): RedirectResponse
    {
        $this->bus->dispatch(new TestLogMessage('Async test log'));

        $this->addFlash('success', 'Async log dispatched — run "messenger:consume async" to process it.');

        return $this->redirectToRoute('app_demo');
    }
}
