<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Model;

use LogHub\MonologBundle\Model\ResourceAttributes;
use PHPUnit\Framework\TestCase;

final class ResourceAttributesTest extends TestCase
{
    public function testWithAddsKeyImmutably(): void
    {
        $original = new ResourceAttributes(['service.name' => 'billing-api']);
        $updated = $original->with('host.name', 'api-02');

        self::assertSame(['service.name' => 'billing-api'], $original->toArray());
        self::assertSame(['service.name' => 'billing-api', 'host.name' => 'api-02'], $updated->toArray());
        self::assertSame($updated->toArray(), $updated->jsonSerialize());
    }
}
