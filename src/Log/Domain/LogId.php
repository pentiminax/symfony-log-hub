<?php

declare(strict_types=1);

namespace App\Log\Domain;

use Symfony\Component\Uid\Uuid;

final readonly class LogId
{
    private function __construct(private Uuid $uuid)
    {
    }

    public static function generate(): self
    {
        return new self(Uuid::v7());
    }

    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value));
    }

    public function equals(self $other): bool
    {
        return $this->uuid->equals($other->uuid);
    }

    public function __toString(): string
    {
        return $this->uuid->toRfc4122();
    }
}
