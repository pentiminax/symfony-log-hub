<?php

declare(strict_types=1);

namespace App\Log\Domain;

final readonly class ResourceAttributes
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
