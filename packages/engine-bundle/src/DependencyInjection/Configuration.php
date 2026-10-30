<?php

declare(strict_types=1);

namespace LogHub\EngineBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('loghub_engine');
        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('url')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('master_key')->isRequired()->cannotBeEmpty()->end()
            ->end();

        return $treeBuilder;
    }
}
