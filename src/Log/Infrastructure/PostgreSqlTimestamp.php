<?php

declare(strict_types=1);

namespace App\Log\Infrastructure;

/**
 * Binds a timestamp with the microseconds intact.
 *
 * `log_record.timestamp` is a `timestamptz(6)`, but DBAL's DATETIMETZ_IMMUTABLE type
 * formats through PostgreSQLPlatform::getDateTimeTzFormatString(), which stops at
 * whole seconds. Binding through it silently rounds every log to the second on the
 * way in — and rounds cursor and range bounds the same way on the way out, which is
 * exactly the precision keyset pagination is built on.
 */
final readonly class PostgreSqlTimestamp
{
    private const string FORMAT = 'Y-m-d H:i:s.uP';

    public static function bind(\DateTimeInterface $value): string
    {
        return $value->format(self::FORMAT);
    }
}
