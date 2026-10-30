<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Redaction;

use LogHub\SymfonyBundle\Model\LogAttributes;
use LogHub\SymfonyBundle\Model\LogRecord;
use LogHub\SymfonyBundle\Model\ResourceAttributes;

final class AttributeRedactor
{
    private const array DEFAULT_SENSITIVE_KEYS = [
        'password', 'passwd', 'secret', 'token', 'access_token',
        'refresh_token', 'authorization', 'cookie', 'set-cookie',
        'api_key', 'apikey',
    ];

    private const string REPLACEMENT = '[REDACTED]';

    /** @var list<string> */
    private readonly array $sensitiveKeys;

    /** @param list<string> $extraSensitiveKeys */
    public function __construct(array $extraSensitiveKeys = [])
    {
        $this->sensitiveKeys = array_map(
            strtolower(...),
            [...self::DEFAULT_SENSITIVE_KEYS, ...$extraSensitiveKeys],
        );
    }

    public function redact(LogRecord $record): LogRecord
    {
        $record = $record->withAttributes(new LogAttributes($this->redactArray($record->attributes->toArray())));

        return $record->withResource(new ResourceAttributes($this->redactArray($record->resource->toArray())));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function redactArray(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            $redacted[$key] = match (true) {
                \in_array(strtolower((string) $key), $this->sensitiveKeys, true) => self::REPLACEMENT,
                \is_array($value) => $this->redactArray($value),
                default => $value,
            };
        }

        return $redacted;
    }
}
