<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Log\Application\DateRange;
use App\Log\Application\LogSearchQuery;
use App\Log\Application\LogSort;
use App\Log\Application\Pagination;
use App\Log\Domain\ProjectId;
use App\Log\Infrastructure\PostgreSqlLogSearchStore;
use App\Project\Application\ApiKeyFactory;
use App\Project\Domain\Project;
use App\Project\Infrastructure\DoctrineApiKeyRepository;
use App\Project\Infrastructure\DoctrineProjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LogIngestionControllerTest extends WebTestCase
{
    private const URL = '/api/v1/logs';

    private KernelBrowser $client;
    private string $token;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $pepper = self::getContainer()->getParameter('kernel.secret');

        $apiKeys = new DoctrineApiKeyRepository($entityManager, $pepper);
        $factory = new ApiKeyFactory($pepper);

        self::getContainer()->get(Connection::class)->executeStatement('TRUNCATE TABLE project CASCADE');
        self::getContainer()->get(Connection::class)->executeStatement('TRUNCATE TABLE log_record');

        $this->project = Project::create('checkout');
        (new DoctrineProjectRepository($entityManager))->save($this->project);

        $generated = $factory->generate($this->project->id, 'default');
        $apiKeys->save($generated->apiKey);
        $this->token = $generated->plaintext;
    }

    public function testAFullyValidBatchIsAcceptedAndPersisted(): void
    {
        $this->post(['records' => [$this->validRecord(), $this->validRecord()]]);

        self::assertResponseStatusCodeSame(202);
        $body = $this->decodedResponse();
        self::assertSame(2, $body['accepted']);
        self::assertSame(0, $body['rejected']);
        self::assertSame(2, $this->persistedCount());
    }

    public function testARecordMissingARequiredFieldIsRejectedButTheRestOfTheBatchIsAccepted(): void
    {
        $malformed = $this->validRecord();
        unset($malformed['message']);

        $this->post(['records' => [$this->validRecord(), $malformed]]);

        self::assertResponseStatusCodeSame(202);
        $body = $this->decodedResponse();
        self::assertSame(1, $body['accepted']);
        self::assertSame(1, $body['rejected']);
        self::assertSame(1, $body['errors'][0]['index']);
        self::assertSame(1, $this->persistedCount());
    }

    public function testAProjectIdInThePayloadIsIgnoredInFavorOfTheAuthenticatedProject(): void
    {
        $record = $this->validRecord();
        $record['project_id'] = (string) ProjectId::generate();

        $this->post(['records' => [$record]]);

        self::assertResponseStatusCodeSame(202);
        // Persisted under $this->project (the authenticated project), not the spoofed id above.
        self::assertSame(1, $this->persistedCount());
    }

    public function testAnUnauthenticatedRequestIsRejected(): void
    {
        $this->client->request(
            'POST',
            self::URL,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['records' => [$this->validRecord()]]),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testABatchOverTheRecordLimitIsRejectedWhole(): void
    {
        $this->post(['records' => array_fill(0, 101, $this->validRecord())]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->persistedCount());
    }

    public function testAMessageOverTheSizeLimitIsRejectedButTheRestOfTheBatchIsAccepted(): void
    {
        $tooLong = $this->validRecord();
        $tooLong['message'] = str_repeat('a', 200_000);

        $this->post(['records' => [$this->validRecord(), $tooLong]]);

        self::assertResponseStatusCodeSame(202);
        $body = $this->decodedResponse();
        self::assertSame(1, $body['accepted']);
        self::assertSame(1, $body['rejected']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): void
    {
        $this->client->request(
            'POST',
            self::URL,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($payload),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedResponse(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function persistedCount(): int
    {
        $searchStore = new PostgreSqlLogSearchStore(self::getContainer()->get(Connection::class));

        $result = $searchStore->search(new LogSearchQuery(
            projectId: $this->project->id,
            range: new DateRange(),
            search: null,
            filters: [],
            sort: new LogSort('timestamp'),
            pagination: new Pagination(1000),
        ));

        return $result->records->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function validRecord(): array
    {
        return [
            'timestamp' => '2026-09-09T14:45:01.231Z',
            'severity' => ['text' => 'ERROR', 'number' => 17],
            'message' => 'Payment failed',
            'channel' => 'payment',
            'resource' => [
                'service.name' => 'billing-api',
                'service.version' => '1.4.2',
            ],
            'attributes' => [
                'payment_id' => 123,
                'provider' => 'stripe',
            ],
            'trace_id' => null,
            'span_id' => null,
            'exception' => [
                'type' => 'RuntimeException',
                'message' => 'Provider unavailable',
                'stacktrace' => '...',
            ],
        ];
    }
}
