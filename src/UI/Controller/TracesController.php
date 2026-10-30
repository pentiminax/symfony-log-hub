<?php

declare(strict_types=1);

namespace App\UI\Controller;

use App\Log\Application\Filter;
use App\Log\Application\FilterOperator;
use App\Log\Domain\ProjectId;
use App\Project\Application\ProjectRepositoryInterface;
use App\Project\Domain\Project;
use App\Trace\Application\TraceCursor;
use App\Trace\Application\TraceSearchQuery;
use App\Trace\Application\TraceSearchStoreInterface;
use App\Trace\Domain\TraceId;
use App\UI\TimeRangePreset;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Plain controller + query string rather than a Live Component: the list is read-only,
 * paginated forward with a cursor link, and a GET form covers every filter, so there is
 * no state worth round-tripping.
 */
final class TracesController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly TraceSearchStoreInterface $store,
    ) {
    }

    #[Route('/traces', name: 'app_traces', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $project = $this->project($request);
        $range = TimeRangePreset::tryFrom($request->query->getString('range')) ?? TimeRangePreset::LAST_24_HOURS;
        if (TimeRangePreset::CUSTOM === $range) {
            $range = TimeRangePreset::LAST_24_HOURS;
        }
        $service = trim($request->query->getString('service'));
        $errorsOnly = $request->query->getBoolean('errors');

        $result = null;
        $error = null;
        if (null !== $project) {
            $filters = [];
            if ('' !== $service) {
                $filters[] = new Filter('service.name', FilterOperator::EQUALS, $service);
            }
            if ($errorsOnly) {
                $filters[] = new Filter('status', FilterOperator::EQUALS, 'error');
            }

            try {
                $result = $this->store->search(new TraceSearchQuery(
                    $project->id,
                    $range->resolve(),
                    $filters,
                    self::PER_PAGE,
                    $this->cursor($request->query->getString('cursor')),
                ));
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        $nextCursor = $result?->nextCursor;

        return $this->render('traces/index.html.twig', [
            'projects' => $this->projects->findAll(),
            'project' => $project,
            'presets' => array_filter(TimeRangePreset::cases(), static fn (TimeRangePreset $p): bool => TimeRangePreset::CUSTOM !== $p),
            'range' => $range,
            'service' => $service,
            'errorsOnly' => $errorsOnly,
            'result' => $result,
            'error' => $error,
            'nextCursor' => null === $nextCursor ? null : $nextCursor->startTime->format('Y-m-d\TH:i:s.uP').'|'.$nextCursor->traceId,
            'paged' => '' !== $request->query->getString('cursor'),
        ]);
    }

    #[Route('/traces/{traceId}', name: 'app_trace_show', methods: ['GET'])]
    public function show(string $traceId, Request $request): Response
    {
        $project = $this->project($request);

        try {
            $trace = null === $project ? null : $this->store->find($project->id, TraceId::fromString($traceId));
        } catch (\InvalidArgumentException) {
            $trace = null;
        }

        if (null === $trace) {
            throw $this->createNotFoundException('Trace not found.');
        }

        return $this->render('traces/show.html.twig', ['project' => $project, 'trace' => $trace]);
    }

    private function project(Request $request): ?Project
    {
        $id = $request->query->getString('project');
        $project = '' !== $id && Uuid::isValid($id) ? $this->projects->findById(ProjectId::fromString($id)) : null;

        // Same landing rule as the log explorer: no ?project= means the first project.
        return $project ?? ($this->projects->findAll()[0] ?? null);
    }

    private function cursor(string $encoded): ?TraceCursor
    {
        [$startTime, $id] = array_pad(explode('|', $encoded, 2), 2, '');

        try {
            return '' === $encoded ? null : new TraceCursor(new \DateTimeImmutable($startTime), TraceId::fromString($id));
        } catch (\DateMalformedStringException|\InvalidArgumentException) {
            return null;
        }
    }
}
