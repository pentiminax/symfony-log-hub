<?php

declare(strict_types=1);

namespace App\Log\Infrastructure;

use App\Log\Application\LogIngestStoreInterface;
use App\Log\Domain\LogRecord;
use App\Log\Domain\LogRecordCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class PostgreSqlLogIngestStore implements LogIngestStoreInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function storeBatch(LogRecordCollection $records): void
    {
        if (0 === count($records)) {
            return;
        }

        $this->connection->transactional(function (Connection $connection) use ($records): void {
            foreach ($records as $record) {
                $connection->insert('log_record', $this->toRow($record), [
                    'timestamp' => Types::STRING,
                    'resource' => Types::JSON,
                    'attributes' => Types::JSON,
                    'exception' => Types::JSON,
                    'ingested_at' => Types::STRING,
                ]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(LogRecord $record): array
    {
        return [
            'id' => (string) $record->id,
            'project_id' => (string) $record->projectId,
            'timestamp' => PostgreSqlTimestamp::bind($record->timestamp),
            'severity_text' => $record->severity->text,
            'severity_number' => $record->severity->number,
            'message' => $record->message,
            'channel' => $record->channel,
            'service_name' => $record->resource->get('service.name'),
            'service_version' => $record->resource->get('service.version'),
            'environment' => $record->resource->get('environment'),
            'host_name' => $record->resource->get('host.name'),
            'trace_id' => $record->trace?->traceId,
            'span_id' => $record->trace?->spanId,
            'resource' => $record->resource->toArray(),
            'attributes' => $record->attributes->toArray(),
            'exception' => null === $record->exception ? null : [
                'type' => $record->exception->type,
                'message' => $record->exception->message,
                'stacktrace' => $record->exception->stacktrace,
            ],
            'ingested_at' => PostgreSqlTimestamp::bind(new \DateTimeImmutable()),
        ];
    }
}
