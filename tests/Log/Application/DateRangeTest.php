<?php

declare(strict_types=1);

namespace App\Tests\Log\Application;

use App\Log\Application\DateRange;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    public function testFromBeforeToIsAccepted(): void
    {
        $range = new DateRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));

        self::assertNotNull($range->from);
        self::assertNotNull($range->to);
    }

    public function testFromAfterToIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DateRange(new \DateTimeImmutable('2026-01-02'), new \DateTimeImmutable('2026-01-01'));
    }
}
