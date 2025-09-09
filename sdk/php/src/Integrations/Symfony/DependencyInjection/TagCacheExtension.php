<?php

namespace TagCache\Integrations\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Definition;
use TagCache\Client;
use TagCache\Config;

class TagCacheExtension extends Extension
{
    /**
     * @param array<mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = [];
        foreach ($configs as $c) { $config = array_replace_recursive($config, $c); }
        $container->setParameter('tagcache.config', $config);

        $def = new Definition(Config::class, [$config]);
        $container->setDefinition('tagcache.config', $def);

        $clientDef = new Definition(Client::class);
        $clientDef->setArguments([\Symfony\Component\DependencyInjection\Reference::class => null]);
        $clientDef->setArgument(0, \Symfony\Component\DependencyInjection\Reference::fromString('tagcache.config'));
        $container->setDefinition('tagcache.client', $clientDef);
    }
}
