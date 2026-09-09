<?php

declare(strict_types=1);

namespace App\Log\Domain;

final readonly class ExceptionData
{
    public function __construct(
        public string $type,
        public string $message,
        public ?string $stacktrace = null,
    ) {
    }
}
