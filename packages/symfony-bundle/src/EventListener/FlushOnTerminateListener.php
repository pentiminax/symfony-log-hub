<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\EventListener;

use LogHub\SymfonyBundle\Handler\LogHubHandler;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final readonly class FlushOnTerminateListener
{
    public function __construct(
        private LogHubHandler $handler,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    // A messenger worker is one long console command: flush after each message, not only at exit.
    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onTerminate(): void
    {
        $this->handler->flush();
    }
}
