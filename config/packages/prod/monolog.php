<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sentry\Monolog\BreadcrumbHandler;
use Sentry\Monolog\Handler;

return App::config([
    'monolog' => [
        'handlers' => [
            'main' => [
                'type' => 'fingers_crossed',
                'action_level' => 'warning',
                'handler' => 'grouped',
                'excluded_http_codes' => [404, 405],
                'buffer_size' => 50,
            ],
            'grouped' => [
                'type' => 'group',
                'members' => ['nested', 'sentry'],
            ],
            'nested' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'debug',
                'formatter' => 'monolog.formatter.json',
            ],
            'sentry' => [
                'type' => 'service',
                'id' => Handler::class,
            ],
            'console' => [
                'type' => 'console',
                'process_psr_3_messages' => false,
                'channels' => ['!event', '!doctrine'],
            ],
            'sentry_breadcrumbs' => [
                'type' => 'service',
                'id' => BreadcrumbHandler::class,
                'level' => 'info',
            ],
        ],
    ],
]);
