<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Model;

use LogHub\MonologBundle\Model\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testJsonSerializeProducesTextAndNumber(): void
    {
        $severity = new Severity('ERROR', 17);

        self::assertSame(['text' => 'ERROR', 'number' => 17], $severity->jsonSerialize());
    }
}
