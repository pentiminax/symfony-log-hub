<?php

namespace App\Message;

final readonly class TestLogMessage
{
    public function __construct(
        public string $content,
    ) {
    }
}
