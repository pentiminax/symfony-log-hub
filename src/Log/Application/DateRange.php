<?php

declare(strict_types=1);

namespace App\Log\Application;

final readonly class DateRange
{
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {
        if (null !== $from && null !== $to && $from > $to) {
            throw new \InvalidArgumentException('DateRange "from" must not be after "to".');
        }
    }
}
