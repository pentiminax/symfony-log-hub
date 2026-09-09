<?php

declare(strict_types=1);

namespace App\Tests\Log\Domain;

use App\Log\Domain\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testValidNumberIsAccepted(): void
    {
        $severity = new Severity('ERROR', 17);

        self::assertSame('ERROR', $severity->text);
        self::assertSame(17, $severity->number);
    }

    public function testNumberBelowRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Severity('INVALID', 0);
    }

    public function testNumberAboveRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Severity('INVALID', 25);
    }
}
