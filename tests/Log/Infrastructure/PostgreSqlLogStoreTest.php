<?php

declare(strict_types=1);

namespace App\Tests\Log\Infrastructure;

use App\Log\Application\DateRange;
use App\Log\Application\Filter;
use App\Log\Application\FilterOperator;
use App\Log\Application\LogSearchQuery;
use App\Log\Application\LogSort;
use App\Log\Application\Pagination;
use App\Log\Domain\ExceptionData;
use App\Log\Domain\LogAttributes;
use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\LogRecordCollection;
use App\Log\Domain\ProjectId;
use App\Log\Domain\ResourceAttributes;
use App\Log\Domain\Severity;
use App\Log\Domain\TraceContext;
use App\Log\Infrastructure\PostgreSqlLogIngestStore;
use App\Log\Infrastructure\PostgreSqlLogSearchStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlLogStoreTest extends KernelTestCase
{
    private Connection $connection;
    private PostgreSqlLogIngestStore $ingestStore;
    private PostgreSqlLogSearchStore $searchStore;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->ingestStore = new PostgreSqlLogIngestStore($this->connection);
        $this->searchStore = new PostgreSqlLogSearchStore($this->connection);
        $this->connection->executeStatement('TRUNCATE TABLE log_record');
    }

    public function testStoreAndFindRoundTrip(): void
    {
        $record = $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 12345);

        $this->ingestStore->storeBatch(new LogRecordCollection($record));

        $found = $this->searchStore->find($record->id);

        self::assertNotNull($found);
        self::assertTrue($record->id->equals($found->id));
        self::assertSame('payment failed', $found->message);
        self::assertSame(12345, $found->attributes->get('payment_id'));
        self::assertSame('checkout', $found->resource->get('service.name'));
        self::assertSame('trace-abc', $found->trace?->traceId);
        self::assertSame('RuntimeException', $found->exception?->type);
    }

    public function testSearchFiltersOnJsonAttributeWithNumericSemantics(): void
    {
        $this->ingestStore->storeBatch(new LogRecordCollection(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 111),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:01:00'), 999),
        ));

        $result = $this->searchStore->search(new LogSearchQuery(
            projectId: $this->projectId,
            range: new DateRange(),
            search: null,
            filters: [new Filter('attributes.payment_id', FilterOperator::GREATER_THAN, 500)],
            sort: new LogSort('timestamp'),
            pagination: new Pagination(10),
        ));

        self::assertSame(1, $result->records->count());
        self::assertSame(999, $result->records->toArray()[0]->attributes->get('payment_id'));
    }

    public function testSearchPaginatesWithCursor(): void
    {
        $this->ingestStore->storeBatch(new LogRecordCollection(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 1),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:01:00'), 2),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:02:00'), 3),
        ));

        $firstPage = $this->searchStore->search(new LogSearchQuery(
            projectId: $this->projectId,
            range: new DateRange(),
            search: null,
            filters: [],
            sort: new LogSort('timestamp'),
            pagination: new Pagination(2),
        ));

        self::assertSame(2, $firstPage->records->count());
        self::assertTrue($firstPage->hasMore);
        self::assertNotNull($firstPage->nextCursor);

        $secondPage = $this->searchStore->search(new LogSearchQuery(
            projectId: $this->projectId,
            range: new DateRange(),
            search: null,
            filters: [],
            sort: new LogSort('timestamp'),
            pagination: new Pagination(2, $firstPage->nextCursor),
        ));

        self::assertSame(1, $secondPage->records->count());
        self::assertFalse($secondPage->hasMore);
    }

    private ProjectId $projectId;

    private function makeRecord(\DateTimeImmutable $timestamp, int $paymentId): LogRecord
    {
        $this->projectId ??= ProjectId::generate();

        return new LogRecord(
            id: LogId::generate(),
            projectId: $this->projectId,
            timestamp: $timestamp,
            severity: new Severity('ERROR', 17),
            message: 'payment failed',
            channel: 'app',
            resource: new ResourceAttributes([
                'service.name' => 'checkout',
                'environment' => 'prod',
            ]),
            attributes: new LogAttributes([
                'payment_id' => $paymentId,
            ]),
            trace: new TraceContext('trace-abc', 'span-abc'),
            exception: new ExceptionData('RuntimeException', 'boom', "#0 {main}"),
        );
    }
}
