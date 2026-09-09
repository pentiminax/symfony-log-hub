<?php

declare(strict_types=1);

namespace App\Tests\Log\Infrastructure;

use App\Log\Application\DateRange;
use App\Log\Application\Filter;
use App\Log\Application\FilterOperator;
use App\Log\Application\LogCursor;
use App\Log\Application\LogSearchQuery;
use App\Log\Application\LogSearchResult;
use App\Log\Application\LogSort;
use App\Log\Application\Pagination;
use App\Log\Application\SortDirection;
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
        $this->projectId = ProjectId::generate();
        $this->connection->executeStatement('TRUNCATE TABLE log_record');
    }

    /**
     * Microseconds survive the round trip. They do not with DBAL's own timestamptz
     * type, which formats to whole seconds — and every cursor comparison is built
     * on this precision.
     */
    public function testTheStoredTimestampKeepsItsMicroseconds(): void
    {
        $timestamp = new \DateTimeImmutable('2026-01-01 10:00:00.123456+00:00');
        $record = $this->makeRecord($timestamp, 1);

        $this->ingestStore->storeBatch(new LogRecordCollection($record));

        self::assertSame(
            '2026-01-01T10:00:00.123456+00:00',
            $this->searchStore->find($record->projectId, $record->id)?->timestamp->format('Y-m-d\\TH:i:s.uP'),
        );
    }

    public function testStoreAndFindRoundTrip(): void
    {
        $record = $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 12345);

        $this->ingestStore->storeBatch(new LogRecordCollection($record));

        $found = $this->searchStore->find($record->projectId, $record->id);

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

    public function testFindIsScopedToItsProject(): void
    {
        $record = $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 1);
        $this->ingestStore->storeBatch(new LogRecordCollection($record));

        self::assertNull($this->searchStore->find(ProjectId::generate(), $record->id));
    }

    public function testCursorPaginationWalksForwardWhenSortedAscending(): void
    {
        $this->ingestStore->storeBatch(new LogRecordCollection(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 1),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:01:00'), 2),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:02:00'), 3),
        ));

        $firstPage = $this->searchStore->search($this->query(
            sort: new LogSort('timestamp', SortDirection::ASC),
            pagination: new Pagination(2),
        ));

        self::assertSame([1, 2], $this->paymentIds($firstPage));
        self::assertNotNull($firstPage->nextCursor);

        $secondPage = $this->searchStore->search($this->query(
            sort: new LogSort('timestamp', SortDirection::ASC),
            pagination: new Pagination(2, $firstPage->nextCursor),
        ));

        self::assertSame([3], $this->paymentIds($secondPage));
        self::assertFalse($secondPage->hasMore);
    }

    public function testCursorPaginationPreservesMicrosecondPrecision(): void
    {
        $this->ingestStore->storeBatch(new LogRecordCollection(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00.000001'), 1),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00.000002'), 2),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00.000003'), 3),
        ));

        $firstPage = $this->searchStore->search($this->query(pagination: new Pagination(2)));
        $secondPage = $this->searchStore->search($this->query(
            pagination: new Pagination(2, $firstPage->nextCursor),
        ));

        self::assertSame([3, 2], $this->paymentIds($firstPage));
        self::assertSame([1], $this->paymentIds($secondPage));
    }

    public function testCursorPaginationRejectsSortingOnAnotherField(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->searchStore->search($this->query(
            sort: new LogSort('severity'),
            pagination: new Pagination(2, new LogCursor(new \DateTimeImmutable(), LogId::generate())),
        ));
    }

    public function testSearchFiltersOnChannel(): void
    {
        $this->ingestStore->storeBatch(new LogRecordCollection(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 1),
        ));

        $matching = $this->searchStore->search($this->query(
            filters: [new Filter('channel', FilterOperator::EQUALS, 'app')],
        ));
        $other = $this->searchStore->search($this->query(
            filters: [new Filter('channel', FilterOperator::EQUALS, 'security')],
        ));

        self::assertSame(1, $matching->records->count());
        self::assertSame(0, $other->records->count());
    }

    public function testSearchFiltersOnAResourceAttributeThatHasNoPromotedColumn(): void
    {
        $this->ingestStore->storeBatch(new LogRecordCollection(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 1),
        ));

        $result = $this->searchStore->search($this->query(
            filters: [new Filter('resource.deployment_region', FilterOperator::EQUALS, 'eu-west-3')],
        ));

        self::assertSame(1, $result->records->count());
    }

    public function testDistinctValuesIsScopedToTheProjectAndRange(): void
    {
        $otherProject = ProjectId::generate();

        $this->ingestStore->storeBatch(new LogRecordCollection(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 1),
            $this->makeRecordFor($otherProject, new \DateTimeImmutable('2026-01-01 10:00:00'), 2, 'billing-api'),
        ));

        $values = $this->searchStore->distinctValues($this->projectId, 'service.name', new DateRange());

        self::assertSame(['checkout'], $values);
    }

    public function testDistinctValuesRejectsANonFacetableField(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->searchStore->distinctValues(ProjectId::generate(), 'message', new DateRange());
    }

    public function testCountUpToSaturatesAtItsBound(): void
    {
        $this->ingestStore->storeBatch(new LogRecordCollection(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 1),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:01:00'), 2),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:02:00'), 3),
        ));

        self::assertSame(3, $this->searchStore->countUpTo($this->query(), 10));
        self::assertSame(2, $this->searchStore->countUpTo($this->query(), 2));
    }

    public function testCountUpToHonoursFilters(): void
    {
        $this->ingestStore->storeBatch(new LogRecordCollection(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 111),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:01:00'), 999),
        ));

        $count = $this->searchStore->countUpTo($this->query(
            filters: [new Filter('attributes.payment_id', FilterOperator::GREATER_THAN, 500)],
        ), 10);

        self::assertSame(1, $count);
    }

    /**
     * @param list<Filter> $filters
     */
    private function query(
        ?string $search = null,
        array $filters = [],
        ?LogSort $sort = null,
        ?Pagination $pagination = null,
    ): LogSearchQuery {
        return new LogSearchQuery(
            projectId: $this->projectId,
            range: new DateRange(),
            search: $search,
            filters: $filters,
            sort: $sort ?? new LogSort('timestamp'),
            pagination: $pagination ?? new Pagination(10),
        );
    }

    /**
     * @return list<int>
     */
    private function paymentIds(LogSearchResult $result): array
    {
        return array_map(
            static fn (LogRecord $record): int => $record->attributes->get('payment_id'),
            $result->records->toArray(),
        );
    }

    private ProjectId $projectId;

    private function makeRecord(\DateTimeImmutable $timestamp, int $paymentId): LogRecord
    {
        return $this->makeRecordFor($this->projectId, $timestamp, $paymentId);
    }

    private function makeRecordFor(
        ProjectId $projectId,
        \DateTimeImmutable $timestamp,
        int $paymentId,
        string $serviceName = 'checkout',
    ): LogRecord {
        return new LogRecord(
            id: LogId::generate(),
            projectId: $projectId,
            timestamp: $timestamp,
            severity: new Severity('ERROR', 17),
            message: 'payment failed',
            channel: 'app',
            resource: new ResourceAttributes([
                'service.name' => $serviceName,
                'environment' => 'prod',
                'deployment_region' => 'eu-west-3',
            ]),
            attributes: new LogAttributes([
                'payment_id' => $paymentId,
            ]),
            trace: new TraceContext('trace-abc', 'span-abc'),
            exception: new ExceptionData('RuntimeException', 'boom', "#0 {main}"),
        );
    }
}
