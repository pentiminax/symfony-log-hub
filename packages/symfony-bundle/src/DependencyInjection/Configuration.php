<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('loghub');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                // Engine base URL, e.g. http://127.0.0.1:8080
                ->scalarNode('url')->isRequired()->cannotBeEmpty()->end()
                // Project key (lh_...). Empty/unset disables log shipping (the handler becomes a no-op).
                ->scalarNode('api_key')->defaultNull()->end()
                // Master key: only needed by the API client and the loghub:* commands.
                ->scalarNode('master_key')->defaultNull()->end()
                ->scalarNode('environment')->defaultValue('%kernel.environment%')->end()
                ->floatNode('timeout')->defaultValue(0.5)->end()
                ->scalarNode('level')->defaultValue('info')->end()
                ->booleanNode('short_class_names')->defaultTrue()->end()
                ->arrayNode('service')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('version')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('tracing')
                    ->addDefaultsIfNotSet()
                    ->children()
                        // Needs open-telemetry/sdk and open-telemetry/exporter-otlp.
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('batch')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_records')->defaultValue(100)->end()
                        ->integerNode('max_bytes')->defaultValue(1_000_000)->end()
                    ->end()
                ->end()
                ->arrayNode('redaction')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('extra_keys')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
