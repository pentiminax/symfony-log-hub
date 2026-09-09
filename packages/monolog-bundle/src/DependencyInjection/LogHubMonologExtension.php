<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\DependencyInjection;

use LogHub\MonologBundle\Enricher\LogEnricherInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class LogHubMonologExtension extends Extension
{
    public function getAlias(): string
    {
        return 'loghub';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');

        $container->setParameter('loghub.endpoint', $config['endpoint']);
        $container->setParameter('loghub.api_key', $config['api_key']);
        $container->setParameter('loghub.environment', $config['environment']);
        $container->setParameter('loghub.timeout', $config['timeout']);
        $container->setParameter('loghub.short_class_names', $config['short_class_names']);
        $container->setParameter('loghub.service_name', $config['service']['name']);
        $container->setParameter('loghub.service_version', $config['service']['version']);
        $container->setParameter('loghub.batch_max_records', $config['batch']['max_records']);
        $container->setParameter('loghub.batch_max_bytes', $config['batch']['max_bytes']);
        $container->setParameter('loghub.redaction_extra_keys', $config['redaction']['extra_keys']);

        $container->registerForAutoconfiguration(LogEnricherInterface::class)
            ->addTag('loghub.enricher');
    }
}
