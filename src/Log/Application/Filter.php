<?php

declare(strict_types=1);

namespace App\Log\Application;

final readonly class Filter
{
    public function __construct(
        public string $field,
        public FilterOperator $operator,
        public mixed $value = null,
    ) {
    }
}
