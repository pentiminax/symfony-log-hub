<?php

declare(strict_types=1);

namespace App\UI\Component;

use App\Log\Application\DateRange;
use App\Log\Application\Filter;
use App\Log\Application\FilterOperator;
use App\Log\Application\LogCursor;
use App\Log\Application\LogSearchQuery;
use App\Log\Application\LogSearchResult;
use App\Log\Application\LogSearchStoreInterface;
use App\Log\Application\LogSort;
use App\Log\Application\Pagination;
use App\Log\Application\SortDirection;
use App\Log\Domain\LogId;
use App\Log\Domain\LogRecord;
use App\Log\Domain\ProjectId;
use App\UI\SeverityLevel;
use App\UI\TimeRangePreset;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The result table, deliberately a component of its own.
 *
 * `data-poll` re-renders the component it is declared on, so isolating the table is
 * what lets auto-refresh leave the search box, the open menus and the detail drawer
 * — all of which live in {@see LogExplorer} — completely untouched.
 */
#[AsLiveComponent(name: 'LogTable', template: 'components/LogTable.html.twig')]
final class LogTable
{
    use DefaultActionTrait;

    /**
     * Server-side page size. Never driven by the client: `Pagination` has no upper
     * bound, so a crafted request could otherwise ask for a million rows.
     */
    public const int PER_PAGE = 50;

    /** Cap for the row counter; above it the UI says "10 000+". */
    public const int COUNT_CAP = 10_000;

    #[LiveProp(updateFromParent: true)]
    public string $projectId = '';

    #[LiveProp(updateFromParent: true)]
    public ?string $search = null;

    /**
     * Plain arrays rather than `Filter` objects: LiveProps travel through JSON on
     * every round trip, and a scalar shape needs no hydrator.
     *
     * @var list<array{field: string, op: string, value: mixed}>
     */
    #[LiveProp(updateFromParent: true)]
    public array $filters = [];

    /**
     * The preset token, not resolved timestamps: the table re-resolves it against
     * now() on every render, so a poll slides the window instead of freezing it.
     */
    #[LiveProp(updateFromParent: true)]
    public string $range = '24h';

    #[LiveProp(updateFromParent: true)]
    public ?string $from = null;

    #[LiveProp(updateFromParent: true)]
    public ?string $to = null;

    #[LiveProp(updateFromParent: true)]
    public string $sort = 'desc';

    /** @var list<string> */
    #[LiveProp(updateFromParent: true)]
    public array $columns = [];

    #[LiveProp(updateFromParent: true)]
    public ?string $selectedLog = null;

    /** Poll interval in milliseconds; 0 disables auto-refresh. */
    #[LiveProp(updateFromParent: true)]
    public int $refreshMs = 0;

    /**
     * Cursors of the pages already left behind. Writable and *not* inherited: the
     * parent resets it by changing the child's `id`, which discards this instance.
     *
     * @var list<string>
     */
    #[LiveProp(writable: true)]
    public array $cursorStack = [];

    private ?LogSearchResult $result = null;
    private ?string $error = null;
    private ?int $total = null;

    public function __construct(
        private readonly LogSearchStoreInterface $store,
    ) {
    }

    #[LiveAction]
    public function nextPage(): void
    {
        $next = $this->search()?->nextCursor;

        if (null !== $next) {
            $this->cursorStack[] = $this->encodeCursor($next);
            $this->result = null;
            $this->total = null;
        }
    }

    #[LiveAction]
    public function previousPage(): void
    {
        array_pop($this->cursorStack);
        $this->result = null;
        $this->total = null;
    }

    /** @return list<LogRecord> */
    public function getRecords(): array
    {
        return $this->search()?->records->toArray() ?? [];
    }

    public function getError(): ?string
    {
        $this->search();

        return $this->error;
    }

    public function hasNextPage(): bool
    {
        return $this->search()?->hasMore ?? false;
    }

    public function hasPreviousPage(): bool
    {
        return [] !== $this->cursorStack;
    }

    public function getPageNumber(): int
    {
        return count($this->cursorStack) + 1;
    }

    /**
     * Bounded on purpose: an exact COUNT(*) over a hot log table is a sequential
     * scan, and nobody reads past "10 000+" anyway.
     */
    public function getTotal(): int
    {
        if (null === $this->total) {
            $this->search();
            $this->total = null !== $this->error
                ? 0
                : $this->store->countUpTo($this->buildQuery(null), self::COUNT_CAP);
        }

        return $this->total;
    }

    public function isTotalCapped(): bool
    {
        return $this->getTotal() >= self::COUNT_CAP;
    }

    /** True when the range holds nothing at all, as opposed to nothing matching. */
    public function isRangeEmpty(): bool
    {
        return [] === $this->filters && (null === $this->search || '' === $this->search);
    }

    public function severityOf(LogRecord $record): SeverityLevel
    {
        return SeverityLevel::fromNumber($record->severity->number);
    }

    /** Value of a dynamic column, resolved against the JSONB already loaded. */
    public function cell(LogRecord $record, string $path): ?string
    {
        $value = match (true) {
            str_starts_with($path, 'attributes.') => $record->attributes->get(substr($path, 11)),
            str_starts_with($path, 'resource.') => $record->resource->get(substr($path, 9)),
            'channel' === $path => $record->channel,
            'severity.text' === $path => $record->severity->text,
            'trace.id' === $path => $record->trace?->traceId,
            'span.id' === $path => $record->trace?->spanId,
            default => null,
        };

        if (null === $value || is_scalar($value)) {
            return null === $value ? null : (string) $value;
        }

        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: null;
    }

    public function isSelected(LogRecord $record): bool
    {
        return null !== $this->selectedLog && $this->selectedLog === (string) $record->id;
    }

    /**
     * Memoized rather than a `#[PreReRender]` hook: that hook does not fire on the
     * initial render, so the first paint would show an empty table.
     */
    private function search(): ?LogSearchResult
    {
        if (null !== $this->result || null !== $this->error) {
            return $this->result;
        }

        try {
            $this->result = $this->store->search($this->buildQuery($this->currentCursor()));
        } catch (\InvalidArgumentException|DbalException $e) {
            // Every way this query can be wrong ends up here: an unknown field, a
            // malformed cursor, a broken range. Validating up front would only
            // duplicate the store's own rules, worse.
            $this->error = $e->getMessage();
        }

        return $this->result;
    }

    private function buildQuery(?LogCursor $cursor): LogSearchQuery
    {
        // tryFrom throughout: these values reach us from the URL, and a typo there
        // deserves the default view, not a \ValueError escaping as a 500.
        $preset = TimeRangePreset::tryFrom($this->range) ?? TimeRangePreset::LAST_24_HOURS;

        return new LogSearchQuery(
            ProjectId::fromString($this->projectId),
            $preset->resolve($this->from, $this->to),
            '' === $this->search ? null : $this->search,
            $this->domainFilters(),
            new LogSort('timestamp', SortDirection::tryFrom($this->sort) ?? SortDirection::DESC),
            new Pagination(self::PER_PAGE, $cursor),
        );
    }

    /** @return list<Filter> */
    private function domainFilters(): array
    {
        return array_values(array_map(
            static function (array $f): Filter {
                $operator = FilterOperator::tryFrom($f['op'] ?? '')
                    ?? throw new \InvalidArgumentException(sprintf('Unknown filter operator "%s".', $f['op'] ?? ''));

                return new Filter((string) ($f['field'] ?? ''), $operator, $f['value'] ?? null);
            },
            $this->filters,
        ));
    }

    private function currentCursor(): ?LogCursor
    {
        $encoded = end($this->cursorStack);

        return false === $encoded ? null : $this->decodeCursor($encoded);
    }

    /**
     * Microseconds are not optional: `log_record.timestamp` is a microsecond
     * timestamptz, and truncating to milliseconds makes the keyset comparison skip
     * or repeat rows whenever two logs land in the same millisecond.
     */
    private function encodeCursor(LogCursor $cursor): string
    {
        return $cursor->timestamp->format('Y-m-d\TH:i:s.uP').'|'.$cursor->id;
    }

    private function decodeCursor(string $encoded): ?LogCursor
    {
        [$timestamp, $id] = array_pad(explode('|', $encoded, 2), 2, null);

        try {
            return new LogCursor(new \DateTimeImmutable((string) $timestamp), LogId::fromString((string) $id));
        } catch (\DateMalformedStringException|\InvalidArgumentException) {
            return null;
        }
    }
}
