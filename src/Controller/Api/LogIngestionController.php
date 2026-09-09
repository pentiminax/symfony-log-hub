<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Log\Application\LogIngestStoreInterface;
use App\Log\Domain\LogRecordCollection;
use App\Log\Infrastructure\Http\IngestLogsRequest;
use App\Log\Infrastructure\Http\InvalidLogRecordException;
use App\Log\Infrastructure\Http\LogRecordMapper;
use App\Security\AuthenticatedProject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/v1/logs (spec 04). The `api` firewall (ApiKeyAuthenticator) has already
 * authenticated the request and resolved the owning project by the time this runs; an
 * invalid/missing/revoked key never reaches here. Records are validated and persisted
 * individually (spec 04.5 partial rejection): one malformed record does not reject the
 * rest of the batch.
 */
final class LogIngestionController extends AbstractController
{
    public function __construct(
        private readonly LogIngestStoreInterface $store,
        private readonly LogRecordMapper $mapper,
        #[Autowire(env: 'int:LOGHUB_MAX_REQUEST_BYTES')] private readonly int $maxRequestBytes,
        #[Autowire(env: 'int:LOGHUB_MAX_BATCH_RECORDS')] private readonly int $maxBatchRecords,
    ) {
    }

    #[Route('/api/v1/logs', name: 'log_ingestion', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] IngestLogsRequest $payload): JsonResponse
    {
        if (\strlen($request->getContent()) > $this->maxRequestBytes) {
            return new JsonResponse(
                ['error' => 'request_too_large', 'max_bytes' => $this->maxRequestBytes],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        if (\count($payload->records) > $this->maxBatchRecords) {
            return new JsonResponse(
                ['error' => 'too_many_records', 'max_records' => $this->maxBatchRecords],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var AuthenticatedProject $project */
        $project = $this->getUser();

        $accepted = [];
        $errors = [];

        foreach ($payload->records as $index => $raw) {
            if (!\is_array($raw)) {
                $errors[] = ['index' => $index, 'reason' => 'record must be an object'];
                continue;
            }

            try {
                $accepted[] = $this->mapper->map($raw, $project->projectId);
            } catch (InvalidLogRecordException $e) {
                $errors[] = ['index' => $index, 'reason' => $e->getMessage()];
            }
        }

        $this->store->storeBatch(new LogRecordCollection(...$accepted));

        return new JsonResponse(
            ['accepted' => \count($accepted), 'rejected' => \count($errors), 'errors' => $errors],
            Response::HTTP_ACCEPTED,
        );
    }
}
