<?php

declare(strict_types=1);

namespace App\Log\Application;

final readonly class Pagination
{
    public function __construct(
        public int $limit,
        public ?LogCursor $cursor = null,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException(sprintf('Pagination limit must be at least 1, got %d.', $limit));
        }
    }
}
