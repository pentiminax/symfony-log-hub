<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Buffer;

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use LogHub\MonologBundle\Transport\BatchTransportInterface;
use PHPUnit\Framework\TestCase;

final class RecordingTransport implements BatchTransportInterface
{
    /** @var list<array> */
    public array $calls = [];

    public function send(array $records): void
    {
        $this->calls[] = $records;
    }
}

final class LogBufferTest extends TestCase
{
    private function record(string $message = 'msg'): LogRecord
    {
        return new LogRecord(
            timestamp: new \DateTimeImmutable(),
            severity: new Severity('INFO', 9),
            message: $message,
            channel: null,
            resource: new ResourceAttributes(),
            attributes: new LogAttributes(),
        );
    }

    public function testFlushIsNoOpWhenEmpty(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport);

        $buffer->flush();

        self::assertSame([], $transport->calls);
    }

    public function testAddDoesNotFlushBeforeLimitReached(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport, maxRecords: 3);

        $buffer->add($this->record());
        $buffer->add($this->record());

        self::assertSame([], $transport->calls);
    }

    public function testFlushesWhenRecordLimitReached(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport, maxRecords: 2);

        $buffer->add($this->record('a'));
        $buffer->add($this->record('b'));
        $buffer->add($this->record('c'));

        self::assertCount(1, $transport->calls);
        self::assertCount(2, $transport->calls[0]);
    }

    public function testFlushesWhenByteLimitReached(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport, maxRecords: 1000, maxBytes: 1);

        $buffer->add($this->record('this record alone already exceeds one byte'));

        self::assertCount(1, $transport->calls);
    }

    public function testManualFlushClearsBufferRegardlessOfTransportOutcome(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport, maxRecords: 1000);

        $buffer->add($this->record());
        $buffer->flush();
        $buffer->flush();

        self::assertCount(1, $transport->calls);
    }
}
