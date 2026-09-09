<?php

declare(strict_types=1);

namespace App\Tests\Log\Application;

use App\Log\Application\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testValidLimitIsAccepted(): void
    {
        $pagination = new Pagination(50);

        self::assertSame(50, $pagination->limit);
        self::assertNull($pagination->cursor);
    }

    public function testLimitBelowOneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Pagination(0);
    }
}
