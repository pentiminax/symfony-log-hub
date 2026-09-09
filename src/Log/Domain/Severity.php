<?php

declare(strict_types=1);

namespace App\Log\Domain;

final readonly class Severity
{
    public function __construct(
        public string $text,
        public int $number,
    ) {
        if ($number < 1 || $number > 24) {
            throw new \InvalidArgumentException(sprintf('Severity number must be between 1 and 24, got %d.', $number));
        }
    }
}
