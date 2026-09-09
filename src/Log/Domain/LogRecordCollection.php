<?php

declare(strict_types=1);

namespace App\Log\Domain;

/**
 * @implements \IteratorAggregate<int, LogRecord>
 */
final readonly class LogRecordCollection implements \IteratorAggregate, \Countable
{
    /** @var list<LogRecord> */
    private array $records;

    public function __construct(LogRecord ...$records)
    {
        $this->records = array_values($records);
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->records);
    }

    public function count(): int
    {
        return count($this->records);
    }

    /** @return list<LogRecord> */
    public function toArray(): array
    {
        return $this->records;
    }
}
