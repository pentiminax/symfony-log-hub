<?php

declare(strict_types=1);

namespace LogHub\EngineBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class LogHubEngineExtension extends Extension
{
    public function getAlias(): string
    {
        return 'loghub_engine';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('loghub_engine.url', $config['url']);
        $container->setParameter('loghub_engine.master_key', $config['master_key']);

        (new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config')))->load('services.php');
    }
}
