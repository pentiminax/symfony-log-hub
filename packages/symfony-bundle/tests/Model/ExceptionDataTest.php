<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\Model;

use LogHub\SymfonyBundle\Model\ExceptionData;
use PHPUnit\Framework\TestCase;

final class ExceptionDataTest extends TestCase
{
    public function testJsonSerializeProducesSpecShape(): void
    {
        $exception = new ExceptionData('RuntimeException', 'Provider unavailable', '#0 ...', 0);

        self::assertSame([
            'type' => 'RuntimeException',
            'message' => 'Provider unavailable',
            'stacktrace' => '#0 ...',
            'code' => 0,
        ], $exception->jsonSerialize());
    }
}
