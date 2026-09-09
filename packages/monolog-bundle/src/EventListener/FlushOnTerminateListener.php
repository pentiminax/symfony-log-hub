<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\EventListener;

use LogHub\MonologBundle\Handler\LogHubHandler;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class FlushOnTerminateListener
{
    public function __construct(
        private LogHubHandler $handler,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function onTerminate(): void
    {
        $this->handler->flush();
    }
}
