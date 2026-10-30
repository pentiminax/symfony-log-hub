<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\Model;

use LogHub\SymfonyBundle\Model\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testJsonSerializeProducesTextAndNumber(): void
    {
        $severity = new Severity('ERROR', 17);

        self::assertSame(['text' => 'ERROR', 'number' => 17], $severity->jsonSerialize());
    }
}
