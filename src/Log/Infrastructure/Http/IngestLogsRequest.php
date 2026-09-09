<?php

declare(strict_types=1);

namespace App\Log\Infrastructure\Http;

/**
 * Top-level envelope for POST /api/v1/logs (spec 04.3). Bound via #[MapRequestPayload].
 * Each element of `records` is validated and mapped individually by LogRecordMapper so a
 * single malformed record does not reject the whole batch (spec 04.5 partial rejection).
 */
final class IngestLogsRequest
{
    /** @var list<array<string, mixed>> */
    public array $records = [];
}
