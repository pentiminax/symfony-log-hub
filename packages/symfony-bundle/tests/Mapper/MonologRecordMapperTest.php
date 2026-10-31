<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\Mapper;

use LogHub\SymfonyBundle\Mapper\MonologRecordMapper;
use Monolog\Level;
use Monolog\LogRecord as MonologLogRecord;
use PHPUnit\Framework\TestCase;

final class MonologRecordMapperTest extends TestCase
{
    private MonologRecordMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MonologRecordMapper();
    }

    private function monologRecord(array $context = [], array $extra = []): MonologLogRecord
    {
        return new MonologLogRecord(
            datetime: new \DateTimeImmutable('2026-09-09T14:45:01.231+00:00'),
            channel: 'payment',
            level: Level::Error,
            message: 'Payment failed',
            context: $context,
            extra: $extra,
        );
    }

    public function testMapsCoreFields(): void
    {
        $record = $this->mapper->map($this->monologRecord());

        self::assertSame('Payment failed', $record->message);
        self::assertSame('payment', $record->channel);
        self::assertSame('ERROR', $record->severity->text);
        self::assertSame(17, $record->severity->number);
        self::assertSame('2026-09-09T14:45:01.231Z', $record->timestamp->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function testPlaceholdersAreInterpolatedAndContextKept(): void
    {
        $monolog = $this->monologRecord(context: ['sql' => 'SELECT 1'])->with(message: 'Executing query: {sql}');

        $record = $this->mapper->map($monolog);

        self::assertSame('Executing query: SELECT 1', $record->message);
        self::assertSame(['sql' => 'SELECT 1'], $record->attributes->toArray());
    }

    public function testContextAndExtraBecomeAttributes(): void
    {
        $record = $this->mapper->map($this->monologRecord(
            context: ['payment_id' => 123, 'provider' => 'stripe'],
            extra: ['request.id' => 'abc'],
        ));

        self::assertSame(
            ['payment_id' => 123, 'provider' => 'stripe', 'request.id' => 'abc'],
            $record->attributes->toArray(),
        );
    }

    public function testNonJsonCompatibleContextValuesAreDropped(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            $record = $this->mapper->map($this->monologRecord(context: [
                'kept' => 'value',
                'dropped_resource' => $resource,
                'dropped_object' => new \stdClass(),
            ]));

            self::assertSame(['kept' => 'value'], $record->attributes->toArray());
        } finally {
            fclose($resource);
        }
    }

    public function testNestedArraysAreRecursivelyFiltered(): void
    {
        $record = $this->mapper->map($this->monologRecord(context: [
            'nested' => ['a' => 1, 'b' => new \stdClass()],
        ]));

        self::assertSame(['nested' => ['a' => 1]], $record->attributes->toArray());
    }

    public function testExceptionContextIsExtractedAndRemovedFromAttributes(): void
    {
        $exception = new \RuntimeException('Provider unavailable', 7);

        $record = $this->mapper->map($this->monologRecord(context: [
            'payment_id' => 123,
            'exception' => $exception,
        ]));

        self::assertNotNull($record->exception);
        self::assertSame(\RuntimeException::class, $record->exception->type);
        self::assertSame('Provider unavailable', $record->exception->message);
        self::assertSame(7, $record->exception->code);
        self::assertArrayNotHasKey('exception', $record->attributes->toArray());
        self::assertSame(['payment_id' => 123], $record->attributes->toArray());
    }

    public function testNoExceptionInContextLeavesExceptionNull(): void
    {
        $record = $this->mapper->map($this->monologRecord());

        self::assertNull($record->exception);
    }
}
