<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\Enricher;

use LogHub\SymfonyBundle\Enricher\ResourceAttributesEnricher;
use LogHub\SymfonyBundle\Model\LogAttributes;
use LogHub\SymfonyBundle\Model\LogRecord;
use LogHub\SymfonyBundle\Model\ResourceAttributes;
use LogHub\SymfonyBundle\Model\Severity;
use PHPUnit\Framework\TestCase;

final class ResourceAttributesEnricherTest extends TestCase
{
    private function record(): LogRecord
    {
        return new LogRecord(
            timestamp: new \DateTimeImmutable(),
            severity: new Severity('INFO', 9),
            message: 'msg',
            channel: null,
            resource: new ResourceAttributes(),
            attributes: new LogAttributes(),
        );
    }

    public function testFillsServiceEnvironmentAndHost(): void
    {
        $enricher = new ResourceAttributesEnricher('billing-api', '1.4.2', 'prod');

        $enriched = $enricher->enrich($this->record())->resource->toArray();

        self::assertSame('billing-api', $enriched['service.name']);
        self::assertSame('1.4.2', $enriched['service.version']);
        self::assertSame('prod', $enriched['deployment.environment.name']);
        self::assertArrayHasKey('host.name', $enriched);
    }

    public function testOmitsServiceVersionWhenNull(): void
    {
        $enricher = new ResourceAttributesEnricher('billing-api', null, 'prod');

        $enriched = $enricher->enrich($this->record())->resource->toArray();

        self::assertArrayNotHasKey('service.version', $enriched);
    }
}
