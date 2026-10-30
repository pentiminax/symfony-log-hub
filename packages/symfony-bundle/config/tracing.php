<?php

declare(strict_types=1);

use LogHub\SymfonyBundle\Tracing\MessengerTracingListener;
use LogHub\SymfonyBundle\Tracing\RequestTracingSubscriber;
use LogHub\SymfonyBundle\Tracing\TracerProviderFactory;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(TracerProviderInterface::class)
        ->factory([TracerProviderFactory::class, 'create'])
        ->args(['%loghub.url%', '%loghub.api_key%', '%loghub.service_name%', '%loghub.service_version%', '%loghub.environment%']);

    $services->set(TracerInterface::class)
        ->factory([service(TracerProviderInterface::class), 'getTracer'])
        ->args(['loghub/symfony-bundle']);

    $services->set(RequestTracingSubscriber::class);

    if (class_exists(SendMessageToTransportsEvent::class)) {
        $services->set(MessengerTracingListener::class);
    }
};
