<?php

namespace TagCache\Integrations\Laminas;

use TagCache\Client;
use TagCache\Config;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'factories' => [
                    Client::class => function($container) {
                        $cfg = $container->has('config') ? ($container->get('config')['tagcache'] ?? []) : [];
                        return new Client(new Config($cfg));
                    }
                ]
            ]
        ];
    }
}
