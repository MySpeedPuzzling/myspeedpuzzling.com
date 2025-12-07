<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'monolog' => [
        'handlers' => [
            'main' => [
                'type' => 'stream',
                'path' => '%kernel.logs_dir%/%kernel.environment%.log',
                'level' => 'debug',
                'channels' => ['!event'],
            ],
            'stdout' => [
                'type' => 'stream',
                'path' => 'php://stdout',
                'level' => 'notice',
                'channels' => ['!event', '!doctrine'],
            ],
            'console' => [
                'type' => 'console',
                'process_psr_3_messages' => false,
                'channels' => ['!event', '!doctrine', '!console'],
            ],
        ],
    ],
]);
