<?php

declare(strict_types=1);

namespace App\UI\Component;

use App\Log\Application\DateRange;
use App\Log\Application\LogSearchStoreInterface;
use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\ProjectId;
use App\Project\Application\ProjectRepositoryInterface;
use App\Project\Domain\Project;
use App\UI\SeverityLevel;
use App\UI\TimeRangePreset;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The Discover screen: everything that describes a question, and nothing that
 * answers it. The answer is {@see LogTable}, a child that can poll on its own
 * without disturbing an open menu, the search box or the detail drawer.
 */
#[AsLiveComponent(name: 'LogExplorer', template: 'components/LogExplorer.html.twig')]
final class LogExplorer
{
    use DefaultActionTrait;

    /** Facets offered as dropdowns, in the order the header renders them. */
    private const array FACETS = ['service.name' => 'service', 'environment' => 'env'];

    // Nullable, not empty-string: http_build_query keeps an empty string but drops
    // a null, and an unset filter has no business showing up in a shared link.
    #[LiveProp(writable: true, url: true)]
    public ?string $project = null;

    #[LiveProp(writable: true, url: true)]
    public ?string $q = null;

    #[LiveProp(writable: true, url: true)]
    public string $range = '24h';

    #[LiveProp(writable: true, url: true)]
    public ?string $from = null;

    #[LiveProp(writable: true, url: true)]
    public ?string $to = null;

    #[LiveProp(writable: true, url: true)]
    public ?string $level = null;

    #[LiveProp(writable: true, url: true)]
    public ?string $service = null;

    #[LiveProp(writable: true, url: true)]
    public ?string $env = null;

    /**
     * Ad-hoc filters added from a cell or from the drawer.
     *
     * @var list<array{field: string, op: string, value: mixed}>
     */
    #[LiveProp(writable: true, url: true)]
    public array $f = [];

    #[LiveProp(writable: true, url: true)]
    public string $sort = 'desc';

    /** Id of the log shown in the drawer. In the URL so a finding can be linked. */
    #[LiveProp(writable: true, url: true)]
    public ?string $log = null;

    /** A viewing preference, not part of the question — deliberately not in the URL. */
    #[LiveProp(writable: true)]
    public int $refreshMs = 0;

    /**
     * In the URL rather than in localStorage (07.17): the columns someone added to
     * make a problem legible are half the finding, and a pasted link should carry
     * them to whoever reads it next.
     *
     * @var list<string>
     */
    #[LiveProp(writable: true, url: true)]
    public array $columns = [];

    /**
     * Per-render cache. A single template reads the selected record several times
     * over, and each read would otherwise be its own query.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly LogSearchStoreInterface $store,
    ) {
    }

    #[LiveAction]
    public function addFilter(#[LiveArg] string $field, #[LiveArg] string $op, #[LiveArg] string $value): void
    {
        foreach ($this->f as $existing) {
            if ($existing['field'] === $field && $existing['op'] === $op && $existing['value'] === $value) {
                return;
            }
        }

        $this->f[] = ['field' => $field, 'op' => $op, 'value' => $value];
    }

    #[LiveAction]
    public function removeFilter(#[LiveArg] int $index): void
    {
        unset($this->f[$index]);
        $this->f = array_values($this->f);
    }

    #[LiveAction]
    #[LiveListener('filters:clear')]
    public function clearFilters(): void
    {
        $this->f = [];
        $this->level = null;
        $this->service = null;
        $this->env = null;
    }

    #[LiveAction]
    public function reset(): void
    {
        $this->clearFilters();
        $this->q = null;
        $this->range = TimeRangePreset::LAST_24_HOURS->value;
        $this->from = null;
        $this->to = null;
        $this->sort = 'desc';
        $this->log = null;
    }

    #[LiveAction]
    #[LiveListener('sort:toggle')]
    public function toggleSort(): void
    {
        $this->sort = 'desc' === $this->sort ? 'asc' : 'desc';
    }

    #[LiveAction]
    #[LiveListener('log:open')]
    public function openLog(#[LiveArg] string $id): void
    {
        $this->log = $id;
    }

    #[LiveAction]
    public function closeLog(): void
    {
        $this->log = null;
    }

    #[LiveAction]
    public function addColumn(#[LiveArg] string $column): void
    {
        if (!\in_array($column, $this->columns, true)) {
            $this->columns[] = $column;
        }
    }

    #[LiveAction]
    #[LiveListener('column:remove')]
    public function removeColumn(#[LiveArg] string $column): void
    {
        $this->columns = array_values(array_filter($this->columns, static fn (string $c) => $c !== $column));
    }

    /** @return list<Project> */
    public function getProjects(): array
    {
        return $this->memo['projects'] ??= $this->projects->findAll();
    }

    public function getCurrentProject(): ?Project
    {
        if (\array_key_exists('project', $this->memo)) {
            return $this->memo['project'];
        }

        // First visit has no ?project=: land on the first one rather than an empty
        // screen that looks like a broken install.
        return $this->memo['project'] = $this->resolveProject() ?? ($this->getProjects()[0] ?? null);
    }

    /**
     * Filters handed to the child: the three dropdowns are ordinary filters once
     * they leave the header, so the table only ever knows about one list.
     *
     * @return list<array{field: string, op: string, value: mixed}>
     */
    public function getEffectiveFilters(): array
    {
        $filters = $this->f;

        if (null !== $this->level && null !== $band = SeverityLevel::tryFrom($this->level)) {
            $filters[] = ['field' => 'severity', 'op' => 'between', 'value' => $band->band()];
        }

        foreach (self::FACETS as $field => $prop) {
            if (null !== $this->{$prop} && '' !== $this->{$prop}) {
                $filters[] = ['field' => $field, 'op' => 'equals', 'value' => $this->{$prop}];
            }
        }

        return $filters;
    }

    /**
     * Identity of the current question. Used as the child's DOM id, so changing a
     * filter replaces the table instance — which discards its cursor stack without
     * a line of reset code — while opening the drawer or polling does not.
     */
    public function getSearchKey(): string
    {
        return substr(hash('xxh128', json_encode([
            (string) $this->getCurrentProject()?->id,
            $this->q,
            $this->range,
            $this->from,
            $this->to,
            $this->getEffectiveFilters(),
            $this->sort,
            $this->columns,
        ], \JSON_THROW_ON_ERROR)), 0, 12);
    }

    /** @return list<string> */
    public function facetValues(string $field): array
    {
        $project = $this->getCurrentProject();

        if (null === $project) {
            return [];
        }

        try {
            return $this->store->distinctValues($project->id, $field, $this->resolvedRange());
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /** @return list<SeverityLevel> */
    public function getLevels(): array
    {
        return SeverityLevel::cases();
    }

    /** @return list<TimeRangePreset> */
    public function getRangePresets(): array
    {
        return TimeRangePreset::cases();
    }

    public function getRangeLabel(): string
    {
        return (TimeRangePreset::tryFrom($this->range) ?? TimeRangePreset::LAST_24_HOURS)->label();
    }

    /**
     * Empty string counts as unset: a <select> bound with data-model writes '' for
     * its "All levels" option, and an untouched dropdown is not an active filter.
     */
    public function hasActiveFilters(): bool
    {
        return [] !== $this->f
            || '' !== ($this->level ?? '')
            || '' !== ($this->service ?? '')
            || '' !== ($this->env ?? '');
    }

    /** The record behind `?log=`, or null when the id is bogus or already purged. */
    public function getSelectedRecord(): ?LogRecord
    {
        if (\array_key_exists('record', $this->memo)) {
            return $this->memo['record'];
        }

        $project = $this->getCurrentProject();

        // Guard before LogId::fromString(), which throws on anything but a UUID:
        // `?log=whatever` must be an empty drawer, not a 500.
        $record = null === $project || null === $this->log || !Uuid::isValid($this->log)
            ? null
            : $this->store->find($project->id, LogId::fromString($this->log));

        return $this->memo['record'] = $record;
    }

    /** The drawer's Raw JSON tab: the record as it would be re-ingested. */
    public function getSelectedRaw(): ?string
    {
        $record = $this->getSelectedRecord();

        if (null === $record) {
            return null;
        }

        return json_encode([
            'id' => (string) $record->id,
            'timestamp' => $record->timestamp->format('Y-m-d\TH:i:s.uP'),
            'severity' => ['text' => $record->severity->text, 'number' => $record->severity->number],
            'message' => $record->message,
            'channel' => $record->channel,
            'resource' => $record->resource->toArray(),
            'attributes' => $record->attributes->toArray(),
            'trace' => null === $record->trace ? null : [
                'trace_id' => $record->trace->traceId,
                'span_id' => $record->trace->spanId,
            ],
            'exception' => null === $record->exception ? null : [
                'type' => $record->exception->type,
                'message' => $record->exception->message,
                'stacktrace' => $record->exception->stacktrace,
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: null;
    }

    public function severityOf(LogRecord $record): SeverityLevel
    {
        return SeverityLevel::fromNumber($record->severity->number);
    }

    private function resolveProject(): ?Project
    {
        if (null === $this->project || !Uuid::isValid($this->project)) {
            return null;
        }

        return $this->projects->findById(ProjectId::fromString($this->project));
    }

    private function resolvedRange(): DateRange
    {
        return (TimeRangePreset::tryFrom($this->range) ?? TimeRangePreset::LAST_24_HOURS)
            ->resolve($this->from, $this->to);
    }
}
