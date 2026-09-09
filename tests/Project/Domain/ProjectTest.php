<?php

declare(strict_types=1);

namespace App\Tests\Project\Domain;

use App\Project\Domain\Project;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    public function testEffectiveRetentionFallsBackToTheGlobalDefaultWhenUnset(): void
    {
        $project = Project::create('checkout');

        self::assertSame(30, $project->effectiveRetentionDays(30));
    }

    public function testEffectiveRetentionUsesTheProjectOverrideWhenSet(): void
    {
        $project = Project::create('checkout', retentionDays: 90);

        self::assertSame(90, $project->effectiveRetentionDays(30));
    }
}
