<?php

namespace TagCache\SDK\Integrations\Laminas;

use TagCache\SDK\Client;
use TagCache\SDK\Config;

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
