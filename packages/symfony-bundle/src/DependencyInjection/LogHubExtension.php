<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\DependencyInjection;

use LogHub\SymfonyBundle\Enricher\LogEnricherInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class LogHubExtension extends Extension
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

        $container->setParameter('loghub.url', $config['url']);
        $container->setParameter('loghub.api_key', $config['api_key']);
        $container->setParameter('loghub.master_key', $config['master_key']);

        // api_key may be an env placeholder resolved at runtime, so the no-op decision is made
        // by the transport itself (empty key => nothing is sent), not here.
        $container->setParameter('loghub.environment', $config['environment']);
        $container->setParameter('loghub.timeout', $config['timeout']);
        $container->setParameter('loghub.level', $config['level']);
        $container->setParameter('loghub.short_class_names', $config['short_class_names']);
        $container->setParameter('loghub.service_name', $config['service']['name']);
        $container->setParameter('loghub.service_version', $config['service']['version']);
        $container->setParameter('loghub.batch_max_records', $config['batch']['max_records']);
        $container->setParameter('loghub.batch_max_bytes', $config['batch']['max_bytes']);
        $container->setParameter('loghub.redaction_extra_keys', $config['redaction']['extra_keys']);

        if ($config['tracing']['enabled']) {
            if (!class_exists(TracerProvider::class) || !class_exists(OtlpHttpTransportFactory::class)) {
                throw new LogicException('"loghub.tracing.enabled" requires the open-telemetry/sdk and open-telemetry/exporter-otlp packages: run "composer require open-telemetry/sdk open-telemetry/exporter-otlp".');
            }
            $loader->load('tracing.php');
        }

        $container->registerForAutoconfiguration(LogEnricherInterface::class)
            ->addTag('loghub.enricher');
    }
}
