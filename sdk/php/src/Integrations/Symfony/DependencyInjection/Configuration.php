<?php

namespace TagCache\Integrations\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tagcache');
        $root = $treeBuilder->getRootNode();
        // Keep permissive; SDK Config validates
        $root
            ->children()
                ->scalarNode('mode')->defaultValue('http')->end()
                ->arrayNode('http')->prototype('variable')->end()->end()
                ->arrayNode('tcp')->prototype('variable')->end()->end()
                ->arrayNode('auth')->prototype('variable')->end()->end()
            ->end();
        return $treeBuilder;
    }
}
