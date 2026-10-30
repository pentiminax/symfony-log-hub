<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Buffer;

use LogHub\SymfonyBundle\Model\LogRecord;
use LogHub\SymfonyBundle\Transport\BatchTransportInterface;

final class LogBuffer
{
    /** @var list<LogRecord> */
    private array $records = [];

    private int $bytes = 0;

    public function __construct(
        private readonly BatchTransportInterface $transport,
        private readonly int $maxRecords = 100,
        private readonly int $maxBytes = 1_000_000,
    ) {
    }

    public function add(LogRecord $record): void
    {
        $this->records[] = $record;
        $this->bytes += \strlen(json_encode($record, JSON_THROW_ON_ERROR));

        if (\count($this->records) >= $this->maxRecords || $this->bytes >= $this->maxBytes) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ([] === $this->records) {
            return;
        }

        $records = $this->records;
        $this->records = [];
        $this->bytes = 0;

        $this->transport->send($records);
    }
}
