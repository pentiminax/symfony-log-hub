<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Model;

final readonly class ResourceAttributes implements \JsonSerializable
{
    /** @param array<string, string|int|float|bool|null> $attributes dotted OTel-style keys, e.g. "service.name" */
    public function __construct(
        private array $attributes = [],
    ) {
    }

    public function with(string $key, string|int|float|bool|null $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self($attributes);
    }

    /** @return array<string, string|int|float|bool|null> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->attributes;
    }
}
