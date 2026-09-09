<?php

declare(strict_types=1);

namespace App\Log\Infrastructure\Http;

/**
 * One ingestion record failed structural validation or a per-record limit (spec 04.4/04.6).
 * The message is a non-sensitive diagnostic (field name + reason), never raw record data
 * (spec 04.5: "must not echo sensitive data unnecessarily").
 */
final class InvalidLogRecordException extends \RuntimeException
{
}
