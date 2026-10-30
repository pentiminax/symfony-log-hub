<?php

declare(strict_types=1);

use LogHub\EngineBundle\Client\EngineClient;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('loghub_engine.http_client', HttpClientInterface::class)
        ->factory([HttpClient::class, 'createForBaseUri'])
        // timeout: a hung engine must not freeze the UI for default_socket_timeout (60s).
        ->args(['%loghub_engine.url%', ['auth_bearer' => '%loghub_engine.master_key%', 'timeout' => 5]]);

    $services->set(EngineClient::class)
        ->args([service('loghub_engine.http_client')]);
};
