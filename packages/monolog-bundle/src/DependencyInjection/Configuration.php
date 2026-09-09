<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\DependencyInjection;

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
                ->scalarNode('endpoint')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('api_key')->isRequired()->cannotBeEmpty()->end()
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
