<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Enricher;

use LogHub\SymfonyBundle\Model\LogRecord;

final readonly class ResourceAttributesEnricher implements LogEnricherInterface
{
    public function __construct(
        private string $serviceName,
        private ?string $serviceVersion,
        private string $environment,
    ) {
    }

    public function enrich(LogRecord $record): LogRecord
    {
        $resource = $record->resource
            ->with('service.name', $this->serviceName)
            ->with('deployment.environment.name', $this->environment)
            ->with('host.name', gethostname() ?: null);

        if (null !== $this->serviceVersion) {
            $resource = $resource->with('service.version', $this->serviceVersion);
        }

        return $record->withResource($resource);
    }
}
