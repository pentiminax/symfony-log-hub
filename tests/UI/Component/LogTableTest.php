<?php

declare(strict_types=1);

namespace App\Tests\UI\Component;

use App\Log\Domain\LogAttributes;
use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\ProjectId;
use App\Log\Domain\ResourceAttributes;
use App\Log\Domain\Severity;
use App\Security\User;
use App\Tests\Fake\InMemoryLogSearchStore;
use App\UI\Component\LogTable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class LogTableTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private ProjectId $projectId;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        // The component renders behind the main firewall, so an anonymous test
        // client gets an AccessDeniedException rather than a table. The user has to
        // exist in the database too: the entity provider refreshes it from there on
        // the next request and drops a token it cannot resolve.
        $this->client = self::createClient();
        // Keep the container (and the in-memory store) alive across requests.
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE app_user CASCADE');

        $user = new User('ops@example.com');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $this->client->loginUser($user);

        $this->projectId = ProjectId::generate();
    }

    /**
     * The cursor round-trips through the component (encoded into the cursor stack,
     * decoded on the next render): it must keep microseconds, or rows that share a
     * millisecond get skipped or repeated.
     */
    public function testPagingAcrossRowsOneMicrosecondApartLosesNothing(): void
    {
        $base = new \DateTimeImmutable('2026-01-01 10:00:00.000000');
        $records = [];

        for ($i = 0; $i < LogTable::PER_PAGE + 10; ++$i) {
            $records[] = $this->makeRecord($base->modify(sprintf('+%d microseconds', $i)), $i);
        }

        $this->store(...$records);

        $component = $this->component(['sort' => 'asc']);
        $firstPage = $this->messagesOf($component->render()->crawler());

        $component->call('nextPage');
        $secondPage = $this->messagesOf($component->render()->crawler());

        self::assertCount(LogTable::PER_PAGE, $firstPage);
        self::assertCount(10, $secondPage);
        self::assertSame([], array_intersect($firstPage, $secondPage), 'A row appeared on both pages.');
        self::assertCount(LogTable::PER_PAGE + 10, array_unique([...$firstPage, ...$secondPage]));
    }

    public function testPreviousPageReturnsToTheExactSameRows(): void
    {
        $base = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->store(...array_map(
            fn (int $i) => $this->makeRecord($base->modify(sprintf('+%d seconds', $i)), $i),
            range(0, LogTable::PER_PAGE + 4),
        ));

        $component = $this->component();
        $firstPage = $this->messagesOf($component->render()->crawler());

        $component->call('nextPage');
        $component->call('previousPage');

        self::assertSame($firstPage, $this->messagesOf($component->render()->crawler()));
    }

    public function testSortingAscendingStartsFromTheOldestRow(): void
    {
        $this->store(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 1),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 11:00:00'), 2),
        );

        self::assertSame('log 1', $this->messagesOf($this->component(['sort' => 'asc'])->render()->crawler())[0]);
        self::assertSame('log 2', $this->messagesOf($this->component()->render()->crawler())[0]);
    }

    public function testTheCounterSaysHowManyRowsMatch(): void
    {
        $this->store(
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 10:00:00'), 1),
            $this->makeRecord(new \DateTimeImmutable('2026-01-01 11:00:00'), 2),
        );

        $counter = $this->component()->render()->crawler()->filter('[aria-live="polite"]')->text();

        self::assertSame('2 logs', preg_replace('/\s+/', ' ', trim($counter)));
    }

    /** A filter the store cannot honour is a banner, never a 500. */
    public function testAnUnknownFilterFieldIsReportedInline(): void
    {
        $rendered = $this->component([
            'filters' => [['field' => 'nope.nope', 'op' => 'equals', 'value' => 'x']],
        ])->render()->toString();

        self::assertStringContainsString('role="alert"', $rendered);
    }

    public function testPollingIsDisabledOnceThereIsAPageBehindUs(): void
    {
        $base = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->store(...array_map(
            fn (int $i) => $this->makeRecord($base->modify(sprintf('+%d seconds', $i)), $i),
            range(0, LogTable::PER_PAGE),
        ));

        $component = $this->component(['refreshMs' => 5000]);
        self::assertStringContainsString('data-poll', $component->render()->toString());

        $component->call('nextPage');
        self::assertStringNotContainsString('data-poll', $component->render()->toString());
    }

    /** @param array<string, mixed> $props */
    private function component(array $props = []): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        // The client is passed explicitly: the trait would otherwise pull a *second*
        // client out of the container, one that never saw loginUser().
        return $this->createLiveComponent('LogTable', [
            'projectId' => (string) $this->projectId,
            'range' => 'custom',
            'from' => '2020-01-01T00:00:00+00:00',
            'to' => '2030-01-01T00:00:00+00:00',
            ...$props,
        ], $this->client);
    }

    /**
     * Messages in render order — the cheapest way to assert *which* rows a page
     * holds rather than merely how many.
     *
     * @return list<string>
     */
    private function messagesOf(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('tbody td span.font-mono')->each(
            static fn ($node) => trim($node->text()),
        );
    }

    private function store(LogRecord ...$records): void
    {
        self::getContainer()->get(InMemoryLogSearchStore::class)->add(...$records);
    }

    private function makeRecord(\DateTimeImmutable $timestamp, int $index): LogRecord
    {
        return new LogRecord(
            id: LogId::generate(),
            projectId: $this->projectId,
            timestamp: $timestamp,
            severity: new Severity('ERROR', 17),
            message: sprintf('log %d', $index),
            channel: 'app',
            resource: new ResourceAttributes(['service.name' => 'checkout', 'environment' => 'prod']),
            attributes: new LogAttributes(['index' => $index]),
        );
    }
}
