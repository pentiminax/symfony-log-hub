<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\EventListener;

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\EventListener\FlushOnTerminateListener;
use LogHub\MonologBundle\Handler\LogHubHandler;
use LogHub\MonologBundle\Mapper\MonologRecordMapper;
use LogHub\MonologBundle\Redaction\AttributeRedactor;
use LogHub\MonologBundle\Transport\BatchTransportInterface;
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
