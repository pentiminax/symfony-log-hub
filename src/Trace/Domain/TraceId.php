<?php

declare(strict_types=1);

namespace App\Trace\Domain;

/** An OTel trace id: 32 lowercase hex characters. */
final readonly class TraceId
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $value)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid trace id.', $value));
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
