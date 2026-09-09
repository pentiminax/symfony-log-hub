<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-loghub',
    description: 'Emit a test log through the loghub Monolog handler',
)]
class TestLoghubCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->logger->info('Payment completed', [
            'payment_id' => 123,
            'provider' => 'stripe',
            'password' => 'secret-should-be-redacted',
        ]);

        $io->success('Log emitted through the loghub handler (fail-open — check output above for delivery errors).');

        return Command::SUCCESS;
    }
}
