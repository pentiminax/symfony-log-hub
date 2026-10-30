<?php

declare(strict_types=1);

use LogHub\SymfonyBundle\Buffer\LogBuffer;
use LogHub\SymfonyBundle\EventListener\FlushOnTerminateListener;
use LogHub\SymfonyBundle\Enricher\RequestAttributesEnricher;
use LogHub\SymfonyBundle\Enricher\ResourceAttributesEnricher;
use LogHub\SymfonyBundle\Enricher\ShortClassNameEnricher;
use LogHub\SymfonyBundle\Enricher\TraceContextEnricher;
use LogHub\SymfonyBundle\Handler\LogHubHandler;
use LogHub\SymfonyBundle\Mapper\MonologRecordMapper;
use LogHub\SymfonyBundle\Redaction\AttributeRedactor;
use LogHub\SymfonyBundle\Transport\BatchTransportInterface;
use LogHub\SymfonyBundle\Transport\HttpBatchTransport;
use LogHub\SymfonyBundle\Client\LogHubClient;
use LogHub\SymfonyBundle\Command\CreateApiKeyCommand;
use LogHub\SymfonyBundle\Command\CreateProjectCommand;
use LogHub\SymfonyBundle\Command\ListProjectsCommand;
use LogHub\SymfonyBundle\Command\RevokeApiKeyCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
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

    $services->set(ShortClassNameEnricher::class)
        ->arg('$enabled', '%loghub.short_class_names%')
        ->tag('loghub.enricher');

    if (interface_exists(\OpenTelemetry\API\Trace\SpanInterface::class)) {
        $services->set(TraceContextEnricher::class)
            ->tag('loghub.enricher');
    }

    $services->set(HttpBatchTransport::class)
        ->arg('$url', '%loghub.url%')
        ->arg('$apiKey', '%loghub.api_key%')
        ->arg('$timeout', '%loghub.timeout%');

    $services->alias(BatchTransportInterface::class, HttpBatchTransport::class);

    $services->set(LogBuffer::class)
        ->arg('$maxRecords', '%loghub.batch_max_records%')
        ->arg('$maxBytes', '%loghub.batch_max_bytes%');

    $services->set(LogHubHandler::class)
        ->arg('$enrichers', tagged_iterator('loghub.enricher'))
        ->arg('$level', '%loghub.level%')
        ->public();

    $services->alias('loghub.monolog_handler', LogHubHandler::class)->public();

    $services->set(FlushOnTerminateListener::class);

    $services->set('loghub.http_client', HttpClientInterface::class)
        ->factory([HttpClient::class, 'createForBaseUri'])
        // timeout: a hung engine must not freeze callers for default_socket_timeout (60s).
        ->args(['%loghub.url%', ['auth_bearer' => '%loghub.master_key%', 'timeout' => 5]]);

    $services->set(LogHubClient::class)
        ->args([service('loghub.http_client')]);

    foreach ([CreateProjectCommand::class, ListProjectsCommand::class, CreateApiKeyCommand::class, RevokeApiKeyCommand::class] as $command) {
        $services->set($command)->arg('$masterKey', '%loghub.master_key%');
    }
};
