<?php

declare(strict_types=1);

namespace App\Log\Application;

final readonly class LogSort
{
    public function __construct(
        public string $field,
        public SortDirection $direction = SortDirection::DESC,
    ) {
    }
}
