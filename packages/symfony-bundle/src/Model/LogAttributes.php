<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Model;

final readonly class LogAttributes implements \JsonSerializable
{
    /** @param array<string, mixed> $attributes JSON-compatible values only (spec 3.4) */
    public function __construct(
        private array $attributes = [],
    ) {
    }

    public function with(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self($attributes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->attributes;
    }
}
