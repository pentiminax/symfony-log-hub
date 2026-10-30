<?php

declare(strict_types=1);

namespace App\Tests\UI\Controller;

use App\Log\Domain\ProjectId;
use App\Project\Domain\Project;
use App\Security\User;
use App\Tests\Fake\InMemoryProjectRepository;
use App\Tests\Fake\InMemoryTraceSearchStore;
use App\Trace\Domain\Span;
use App\Trace\Domain\Trace;
use App\Trace\Domain\TraceId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TracesControllerTest extends WebTestCase
{
    private const string TRACE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private KernelBrowser $client;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(Connection::class)->executeStatement('TRUNCATE TABLE app_user CASCADE');

        $user = new User('ops@example.com');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user);

        $this->project = self::getContainer()->get(InMemoryProjectRepository::class)->create('Checkout');
    }

    public function testTheListRendersOneRowPerTrace(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/traces?project='.$this->project->id);

        self::assertResponseIsSuccessful();
        self::assertSame('Traces', $crawler->filter('h1')->text());
        self::assertCount(1, $crawler->filter('tbody tr'));
        $row = $crawler->filter('tbody tr')->first();
        self::assertSame('GET /pay', $row->filter('th a')->text());
        self::assertStringContainsString('api, billing', $row->text());
        self::assertStringContainsString('1 error', $row->text());
        self::assertSame('/traces/'.self::TRACE.'?project='.$this->project->id, $row->filter('th a')->attr('href'));
        self::assertGreaterThan(0, $crawler->filter('th[scope="col"]')->count());
    }

    public function testTheServiceFilterReachesTheStore(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/traces?project='.$this->project->id.'&service=nobody');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No traces in this time range', $crawler->text());
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testAnEmptyProjectShowsTheEmptyState(): void
    {
        $crawler = $this->client->request('GET', '/traces');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No traces in this time range', $crawler->text());
    }

    public function testTheWaterfallNestsSpansAndPositionsTheBars(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/traces/'.self::TRACE.'?project='.$this->project->id);

        self::assertResponseIsSuccessful();
        $items = $crawler->filter('ol > li');
        self::assertCount(4, $items);
        // Depth-first: root, db, charge (child of db), orphan at root level.
        $names = $items->each(static fn ($li): string => $li->filter('summary .truncate')->first()->text());
        self::assertSame(['GET /pay', 'SELECT', 'charge', 'orphan'], $names);
        $indent = $items->each(static function ($li): string {
            preg_match('/padding-left: ([\d.]+)rem/', $li->filter('summary span')->first()->attr('style') ?? '', $m);

            return $m[1];
        });
        self::assertSame(['0', '1.25', '2.5', '0'], $indent);

        $bar = static function ($li): string {
            return $li->filter('summary [role="img"] span')->attr('style');
        };
        self::assertStringContainsString('left: 0.000%; width: 71.429%', $bar($items->eq(0)));   // 100ms of 140ms
        self::assertStringContainsString('left: 7.143%; width: 35.714%', $bar($items->eq(1)));   // +10ms, 50ms
        self::assertStringContainsString('left: 14.286%', $bar($items->eq(2)));
        self::assertStringContainsString('width: 0.400%', $bar($items->eq(3)));                  // tiny span stays visible
        self::assertStringContainsString('GET /pay: 100 ms', $items->eq(0)->filter('[role="img"]')->attr('aria-label'));
        self::assertStringContainsString('failed', $items->eq(1)->filter('[role="img"]')->attr('aria-label'));
        // Details: attributes and the exception stacktrace event.
        self::assertStringContainsString('db.system', $items->eq(1)->text());
        self::assertSame('#0 boom()', trim($items->eq(1)->filter('pre')->text()));
    }

    public function testTheTraceLinksToItsLogs(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/traces/'.self::TRACE.'?project='.$this->project->id);
        $href = $crawler->selectLink('View logs for this trace')->attr('href');

        self::assertStringStartsWith('/?', $href);
        parse_str(parse_url($href, \PHP_URL_QUERY), $query);
        self::assertSame($this->project->id.'', $query['project']);
        self::assertSame([['field' => 'trace.id', 'op' => 'equals', 'value' => self::TRACE]], $query['f']);
    }

    public function testUnknownOrMalformedTracesAre404(): void
    {
        $this->seed();

        $this->client->request('GET', '/traces/'.str_repeat('b', 32).'?project='.$this->project->id);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/traces/not-a-trace?project='.$this->project->id);
        self::assertResponseStatusCodeSame(404);

        // Another project's trace id never resolves.
        $other = self::getContainer()->get(InMemoryProjectRepository::class)->create('Other');
        $this->client->request('GET', '/traces/'.self::TRACE.'?project='.$other->id);
        self::assertResponseStatusCodeSame(404);
    }

    private function seed(): void
    {
        $t0 = new \DateTimeImmutable('-1 hour');
        $span = static fn (string $id, ?string $parent, string $name, int $startMs, int $durMs, string $service, string $status = 'unset', array $attrs = [], array $events = []): Span => new Span(
            str_repeat($id, 16), null === $parent ? null : str_repeat($parent, 16), $name, 'server',
            $t0->modify(sprintf('+%d milliseconds', $startMs)), $durMs * 1_000_000, $status, null, $service, $attrs, [], $events,
        );

        self::getContainer()->get(InMemoryTraceSearchStore::class)->add(ProjectId::fromString((string) $this->project->id), new Trace(TraceId::fromString(self::TRACE), [
            $span('1', null, 'GET /pay', 0, 100, 'api'),
            $span('2', '1', 'SELECT', 10, 50, 'api', 'error', ['db.system' => 'pg'], [['name' => 'exception', 'timestamp' => null, 'attributes' => ['exception.stacktrace' => '#0 boom()']]]),
            $span('3', '2', 'charge', 20, 120, 'billing'),
            $span('4', '9', 'orphan', 140, 0, 'web'),
        ]));
    }
}
