<?php

namespace App\MessageHandler;

use App\Message\TestLogMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TestLogMessageHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TestLogMessage $message): void
    {
        $this->logger->info($message->content, [
            'source' => 'async',
        ]);
    }
}
