<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\EventListener;

use LogHub\SymfonyBundle\Buffer\LogBuffer;
use LogHub\SymfonyBundle\EventListener\FlushOnTerminateListener;
use LogHub\SymfonyBundle\Handler\LogHubHandler;
use LogHub\SymfonyBundle\Mapper\MonologRecordMapper;
use LogHub\SymfonyBundle\Redaction\AttributeRedactor;
use LogHub\SymfonyBundle\Transport\BatchTransportInterface;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class SpyTransport implements BatchTransportInterface
{
    public int $sentBatches = 0;

    public function send(array $records): void
    {
        ++$this->sentBatches;
    }
}

final class FlushOnTerminateListenerTest extends TestCase
{
    public function testOnTerminateFlushesTheHandler(): void
    {
        $transport = new SpyTransport();
        $handler = new LogHubHandler(
            new MonologRecordMapper(),
            [],
            new AttributeRedactor(),
            new LogBuffer($transport, maxRecords: 1000),
        );

        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $logger->info('buffered, not yet sent');

        self::assertSame(0, $transport->sentBatches);

        (new FlushOnTerminateListener($handler))->onTerminate();

        self::assertSame(1, $transport->sentBatches);
    }
}
