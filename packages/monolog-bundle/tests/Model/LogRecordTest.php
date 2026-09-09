<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Model;

use LogHub\MonologBundle\Model\ExceptionData;
use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use PHPUnit\Framework\TestCase;

final class LogRecordTest extends TestCase
{
    public function testJsonSerializeMatchesSpec0403Shape(): void
    {
        $record = new LogRecord(
            timestamp: new \DateTimeImmutable('2026-09-09T14:45:01.231+00:00'),
            severity: new Severity('ERROR', 17),
            message: 'Payment failed',
            channel: 'payment',
            resource: new ResourceAttributes(['service.name' => 'billing-api']),
            attributes: new LogAttributes(['payment_id' => 123, 'provider' => 'stripe']),
            exception: new ExceptionData('RuntimeException', 'Provider unavailable', '#0 ...'),
        );

        $json = json_decode(json_encode($record, JSON_THROW_ON_ERROR), true);

        self::assertSame('2026-09-09T14:45:01.231Z', $json['timestamp']);
        self::assertSame(['text' => 'ERROR', 'number' => 17], $json['severity']);
        self::assertSame('Payment failed', $json['message']);
        self::assertSame('payment', $json['channel']);
        self::assertSame(['service.name' => 'billing-api'], $json['resource']);
        self::assertSame(['payment_id' => 123, 'provider' => 'stripe'], $json['attributes']);
        self::assertNull($json['trace_id']);
        self::assertNull($json['span_id']);
        self::assertSame('RuntimeException', $json['exception']['type']);
    }

    public function testWithHelpersReturnNewInstances(): void
    {
        $record = new LogRecord(
            timestamp: new \DateTimeImmutable(),
            severity: new Severity('INFO', 9),
            message: 'hello',
            channel: null,
            resource: new ResourceAttributes(),
            attributes: new LogAttributes(),
        );

        $withResource = $record->withResource(new ResourceAttributes(['host.name' => 'api-02']));
        $withAttributes = $record->withAttributes(new LogAttributes(['k' => 'v']));

        self::assertSame([], $record->resource->toArray());
        self::assertSame(['host.name' => 'api-02'], $withResource->resource->toArray());
        self::assertSame([], $record->attributes->toArray());
        self::assertSame(['k' => 'v'], $withAttributes->attributes->toArray());
    }
}
