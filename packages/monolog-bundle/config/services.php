<?php

declare(strict_types=1);

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\EventListener\FlushOnTerminateListener;
use LogHub\MonologBundle\Enricher\RequestAttributesEnricher;
use LogHub\MonologBundle\Enricher\ResourceAttributesEnricher;
use LogHub\MonologBundle\Handler\LogHubHandler;
use LogHub\MonologBundle\Mapper\MonologRecordMapper;
use LogHub\MonologBundle\Redaction\AttributeRedactor;
use LogHub\MonologBundle\Transport\BatchTransportInterface;
use LogHub\MonologBundle\Transport\HttpBatchTransport;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(MonologRecordMapper::class);

    $services->set(AttributeRedactor::class)
        ->arg('$extraSensitiveKeys', '%loghub.redaction_extra_keys%');

    $services->set(ResourceAttributesEnricher::class)
        ->arg('$serviceName', '%loghub.service_name%')
        ->arg('$serviceVersion', '%loghub.service_version%')
        ->arg('$environment', '%loghub.environment%')
        ->tag('loghub.enricher');

    $services->set(RequestAttributesEnricher::class)
        ->tag('loghub.enricher');

    $services->set(HttpBatchTransport::class)
        ->arg('$endpoint', '%loghub.endpoint%')
        ->arg('$apiKey', '%loghub.api_key%')
        ->arg('$timeout', '%loghub.timeout%');

    $services->alias(BatchTransportInterface::class, HttpBatchTransport::class);

    $services->set(LogBuffer::class)
        ->arg('$maxRecords', '%loghub.batch_max_records%')
        ->arg('$maxBytes', '%loghub.batch_max_bytes%');

    $services->set(LogHubHandler::class)
        ->arg('$enrichers', tagged_iterator('loghub.enricher'))
        ->public();

    $services->alias('loghub.monolog_handler', LogHubHandler::class)->public();

    $services->set(FlushOnTerminateListener::class);
};
