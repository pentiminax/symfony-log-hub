<?php

declare(strict_types=1);

namespace App\Project\Domain;

use App\Log\Domain\ProjectId;

final readonly class Project
{
    public function __construct(
        public ProjectId $id,
        public string $name,
        public ?int $retentionDays = null,
    ) {
    }

    public static function create(string $name, ?int $retentionDays = null): self
    {
        return new self(ProjectId::generate(), $name, $retentionDays);
    }

    public function rename(string $name): self
    {
        return new self($this->id, $name, $this->retentionDays);
    }

    public function withRetentionDays(?int $retentionDays): self
    {
        return new self($this->id, $this->name, $retentionDays);
    }

    /**
     * The project's own retention setting overrides the global default; falls back to it when unset.
     */
    public function effectiveRetentionDays(int $globalDefaultDays): int
    {
        return $this->retentionDays ?? $globalDefaultDays;
    }
}
