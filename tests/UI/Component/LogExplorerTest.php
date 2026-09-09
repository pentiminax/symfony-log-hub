<?php

declare(strict_types=1);

namespace App\Tests\UI\Component;

use App\Log\Application\LogIngestStoreInterface;
use App\Log\Domain\ExceptionData;
use App\Log\Domain\LogAttributes;
use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\LogRecordCollection;
use App\Log\Domain\ResourceAttributes;
use App\Log\Domain\Severity;
use App\Log\Domain\TraceContext;
use App\Project\Application\ProjectRepositoryInterface;
use App\Project\Domain\Project;
use App\Security\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class LogExplorerTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE log_record');
        $connection->executeStatement('TRUNCATE TABLE project CASCADE');
        $connection->executeStatement('TRUNCATE TABLE app_user CASCADE');

        $user = new User('ops@example.com');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $this->client->loginUser($user);

        $this->project = Project::create('Checkout');
        self::getContainer()->get(ProjectRepositoryInterface::class)->save($this->project);
    }

    /** Without a ?project=, the first project is better than a blank screen. */
    public function testItFallsBackToTheFirstProject(): void
    {
        self::assertStringContainsString('Checkout', $this->explorer()->render()->toString());
    }

    /**
     * ERROR must mean the whole OTel band: the Monolog mapper emits CRITICAL as 18
     * and ALERT as 19, and a `severity = 17` filter would quietly hide both.
     */
    public function testTheLevelFilterCoversTheWholeSeverityBand(): void
    {
        $this->store(
            $this->makeRecord('critical alert', new Severity('CRITICAL', 18)),
            $this->makeRecord('just chatter', new Severity('INFO', 9)),
        );

        $component = $this->explorer(['level' => 'error']);
        $filters = $component->component()->getEffectiveFilters();

        self::assertContains(['field' => 'severity', 'op' => 'between', 'value' => [17, 20]], $filters);
    }

    public function testAddingTheSameFilterTwiceChangesNothing(): void
    {
        $component = $this->explorer();
        $component->call('addFilter', ['field' => 'channel', 'op' => 'equals', 'value' => 'app']);
        $component->call('addFilter', ['field' => 'channel', 'op' => 'equals', 'value' => 'app']);

        self::assertCount(1, $component->component()->f);
    }

    public function testResetClearsEveryPartOfTheQuestion(): void
    {
        $component = $this->explorer(['q' => 'timeout', 'level' => 'error', 'service' => 'checkout', 'sort' => 'asc']);
        $component->call('reset');

        $explorer = $component->component();
        self::assertNull($explorer->q);
        self::assertNull($explorer->level);
        self::assertNull($explorer->service);
        self::assertSame('desc', $explorer->sort);
        self::assertSame([], $explorer->getEffectiveFilters());
    }

    /**
     * The child's id is the identity of the question: it must move when a filter
     * moves (so the cursor stack dies with it) and stand still when the drawer opens.
     */
    public function testTheSearchKeyTracksTheQuestionAndIgnoresTheDrawer(): void
    {
        $base = $this->explorer()->component()->getSearchKey();

        self::assertNotSame($base, $this->explorer(['q' => 'timeout'])->component()->getSearchKey());
        self::assertSame($base, $this->explorer(['log' => (string) LogId::generate()])->component()->getSearchKey());
    }

    public function testOpeningARowShowsTheDrawerWithItsSixSections(): void
    {
        $record = $this->makeRecord('payment failed', new Severity('ERROR', 17));
        $this->store($record);

        $component = $this->explorer();
        $component->emit('log:open', ['id' => (string) $record->id]);

        $crawler = $component->render()->crawler();
        self::assertCount(6, $crawler->filter('[role="tab"]'));
        self::assertStringContainsString('payment failed', $crawler->filter('dialog')->text());
        self::assertStringContainsString('trace-abc', $crawler->filter('#lh-panel-trace')->text());
    }

    /** `?log=` is user input: a bogus value is an empty drawer, never a 500. */
    public function testABogusLogIdRendersAnEmptyDrawerInsteadOfCrashing(): void
    {
        $rendered = $this->explorer(['log' => 'not-a-uuid'])->render();

        self::assertStringContainsString('no longer available', $rendered->toString());
    }

    public function testAddingAColumnFromTheDrawerShowsItInTheTable(): void
    {
        $component = $this->explorer();
        $component->call('addColumn', ['column' => 'attributes.payment_id']);

        self::assertSame(['attributes.payment_id'], $component->component()->columns);

        $component->emit('column:remove', ['column' => 'attributes.payment_id']);
        self::assertSame([], $component->component()->columns);
    }

    public function testTheSortEventFlipsTheDirection(): void
    {
        $component = $this->explorer();
        $component->emit('sort:toggle');

        self::assertSame('asc', $component->component()->sort);
    }

    /** @param array<string, mixed> $props */
    private function explorer(array $props = []): TestLiveComponent
    {
        return $this->createLiveComponent('LogExplorer', [
            'range' => 'custom',
            'from' => '2020-01-01T00:00:00+00:00',
            'to' => '2030-01-01T00:00:00+00:00',
            ...$props,
        ], $this->client);
    }

    private function store(LogRecord ...$records): void
    {
        self::getContainer()->get(LogIngestStoreInterface::class)
            ->storeBatch(new LogRecordCollection(...$records));
    }

    private function makeRecord(string $message, Severity $severity): LogRecord
    {
        return new LogRecord(
            id: LogId::generate(),
            projectId: $this->project->id,
            timestamp: new \DateTimeImmutable('2026-01-01 10:00:00'),
            severity: $severity,
            message: $message,
            channel: 'app',
            resource: new ResourceAttributes(['service.name' => 'checkout', 'environment' => 'prod']),
            attributes: new LogAttributes(['payment_id' => 42]),
            trace: new TraceContext('trace-abc', 'span-abc'),
            exception: new ExceptionData('RuntimeException', 'boom', '#0 {main}'),
        );
    }
}
