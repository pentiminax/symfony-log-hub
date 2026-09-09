<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Handler;

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\Enricher\LogEnricherInterface;
use LogHub\MonologBundle\Handler\LogHubHandler;
use LogHub\MonologBundle\Mapper\MonologRecordMapper;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Redaction\AttributeRedactor;
use LogHub\MonologBundle\Transport\BatchTransportInterface;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class SpyTransport implements BatchTransportInterface
{
    /** @var list<array> */
    public array $calls = [];

    public function send(array $records): void
    {
        $this->calls[] = $records;
    }
}

final class BrokenEnricher implements LogEnricherInterface
{
    public function enrich(LogRecord $record): LogRecord
    {
        throw new \RuntimeException('enricher exploded');
    }
}

final class UppercaseMessageEnricher implements LogEnricherInterface
{
    public function enrich(LogRecord $record): LogRecord
    {
        return $record->withAttributes($record->attributes->with('enriched', true));
    }
}

final class LogHubHandlerTest extends TestCase
{
    public function testRecordFlowsThroughFullPipeline(): void
    {
        $transport = new SpyTransport();
        $handler = new LogHubHandler(
            new MonologRecordMapper(),
            [new UppercaseMessageEnricher()],
            new AttributeRedactor(),
            new LogBuffer($transport, maxRecords: 1000),
        );

        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $logger->info('hello', ['password' => 'secret']);
        $handler->flush();

        self::assertCount(1, $transport->calls);
        /** @var LogRecord $record */
        $record = $transport->calls[0][0];
        self::assertSame('hello', $record->message);
        self::assertTrue($record->attributes->toArray()['enriched']);
        self::assertSame('[REDACTED]', $record->attributes->toArray()['password']);
    }

    public function testBrokenEnricherDoesNotPropagateOrBreakBuffering(): void
    {
        $transport = new SpyTransport();
        $handler = new LogHubHandler(
            new MonologRecordMapper(),
            [new BrokenEnricher()],
            new AttributeRedactor(),
            new LogBuffer($transport, maxRecords: 1000),
        );

        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $logger->info('this must not throw');
        $handler->flush();

        self::assertSame([], $transport->calls);
    }

    public function testLevelFilterAppliesBeforeHandling(): void
    {
        $transport = new SpyTransport();
        $handler = new LogHubHandler(
            new MonologRecordMapper(),
            [],
            new AttributeRedactor(),
            new LogBuffer($transport, maxRecords: 1000),
            level: Level::Warning,
        );

        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $logger->info('below threshold, ignored');
        $handler->flush();

        self::assertSame([], $transport->calls);
    }
}
