<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Model;

final readonly class ExceptionData implements \JsonSerializable
{
    public function __construct(
        public string $type,
        public string $message,
        public string $stacktrace,
        public int|string|null $code = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'stacktrace' => $this->stacktrace,
            'code' => $this->code,
        ];
    }
}
