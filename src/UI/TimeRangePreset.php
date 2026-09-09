<?php

declare(strict_types=1);

namespace App\UI;

use App\Log\Application\DateRange;

/**
 * Time range as a token rather than resolved timestamps.
 *
 * "Last 24 hours" is resolved against now() on every render, so an auto-refresh
 * slides the window on its own and a shared link means "24 hours before the
 * recipient's now", which is what the words say.
 */
enum TimeRangePreset: string
{
    case LAST_15_MINUTES = '15m';
    case LAST_HOUR = '1h';
    case LAST_24_HOURS = '24h';
    case LAST_7_DAYS = '7d';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::LAST_15_MINUTES => 'Last 15 minutes',
            self::LAST_HOUR => 'Last 1 hour',
            self::LAST_24_HOURS => 'Last 24 hours',
            self::LAST_7_DAYS => 'Last 7 days',
            self::CUSTOM => 'Custom range',
        };
    }

    public function resolve(?string $from = null, ?string $to = null): DateRange
    {
        if (self::CUSTOM === $this) {
            return new DateRange($this->parse($from), $this->parse($to));
        }

        $now = new \DateTimeImmutable();

        return new DateRange($now->sub(new \DateInterval(match ($this) {
            self::LAST_15_MINUTES => 'PT15M',
            self::LAST_HOUR => 'PT1H',
            self::LAST_24_HOURS => 'PT24H',
            self::LAST_7_DAYS => 'P7D',
            self::CUSTOM => throw new \LogicException('Unreachable.'),
        })), $now);
    }

    private function parse(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\DateMalformedStringException) {
            return null;
        }
    }
}
