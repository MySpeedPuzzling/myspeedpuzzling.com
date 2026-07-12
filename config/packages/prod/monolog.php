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
                'channels' => ['!sentry_sdk'],
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
                'channels' => ['!sentry_sdk'],
            ],
            // SDK send failures land in stderr logs only — capturing them in Sentry
            // would loop ("failed to send" events sent through the failing transport)
            'sentry_sdk' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'warning',
                'channels' => ['sentry_sdk'],
                'formatter' => 'monolog.formatter.json',
            ],
        ],
    ],
]);
