<?php

declare(strict_types=1);

namespace App\UI;

use App\Log\Application\Filter;
use App\Log\Application\FilterOperator;

/**
 * OpenTelemetry severity bands, as the UI offers them.
 *
 * Bands, not single numbers: the Monolog mapper emits ERROR=17, CRITICAL=18,
 * ALERT=19 and EMERGENCY=21, so filtering "errors" on `severity = 17` would
 * silently drop CRITICAL and ALERT.
 */
enum SeverityLevel: string
{
    case TRACE = 'trace';
    case DEBUG = 'debug';
    case INFO = 'info';
    case WARN = 'warn';
    case ERROR = 'error';
    case FATAL = 'fatal';

    /**
     * @return array{int, int} inclusive severity_number band
     */
    public function band(): array
    {
        return match ($this) {
            self::TRACE => [1, 4],
            self::DEBUG => [5, 8],
            self::INFO => [9, 12],
            self::WARN => [13, 16],
            self::ERROR => [17, 20],
            self::FATAL => [21, 24],
        };
    }

    public static function fromNumber(int $number): self
    {
        return match (true) {
            $number <= 4 => self::TRACE,
            $number <= 8 => self::DEBUG,
            $number <= 12 => self::INFO,
            $number <= 16 => self::WARN,
            $number <= 20 => self::ERROR,
            default => self::FATAL,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Component class defined in assets/styles/app.css, so the badge colour never
     * has to be assembled from a dynamic utility name Tailwind cannot see.
     */
    public function badgeClass(): string
    {
        return 'lh-severity-'.$this->value;
    }

    public function dotClass(): string
    {
        return 'lh-severity-dot-'.$this->value;
    }

    public function toFilter(): Filter
    {
        return new Filter('severity', FilterOperator::BETWEEN, $this->band());
    }
}
