<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Model;

final readonly class Severity implements \JsonSerializable
{
    public function __construct(
        public string $text,
        public int $number,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'text' => $this->text,
            'number' => $this->number,
        ];
    }
}
