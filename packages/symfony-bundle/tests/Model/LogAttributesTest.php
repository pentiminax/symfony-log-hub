<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\Model;

use LogHub\SymfonyBundle\Model\LogAttributes;
use PHPUnit\Framework\TestCase;

final class LogAttributesTest extends TestCase
{
    public function testWithAddsKeyImmutably(): void
    {
        $original = new LogAttributes(['payment_id' => 123]);
        $updated = $original->with('provider', 'stripe');

        self::assertSame(['payment_id' => 123], $original->toArray());
        self::assertSame(['payment_id' => 123, 'provider' => 'stripe'], $updated->toArray());
        self::assertSame($updated->toArray(), $updated->jsonSerialize());
    }
}
