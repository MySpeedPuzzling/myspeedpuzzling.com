<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'monolog' => [
        'handlers' => [
            'main' => [
                'type' => 'fingers_crossed',
                'action_level' => 'warning',
                'handler' => 'nested',
                'excluded_http_codes' => [404, 405],
                'buffer_size' => 50,
            ],
            'nested' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'debug',
                'formatter' => 'monolog.formatter.json',
            ],
            'console' => [
                'type' => 'console',
                'process_psr_3_messages' => false,
                'channels' => ['!event', '!doctrine'],
            ],
        ],
    ],
]);
