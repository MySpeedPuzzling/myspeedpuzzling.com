<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sentry\Monolog\BreadcrumbHandler;
use Sentry\Monolog\Handler;

return App::config([
    'monolog' => [
        'handlers' => [
            'main' => [
                'type' => 'stream',
                'path' => '%kernel.logs_dir%/%kernel.environment%.log',
                'level' => 'debug',
                'channels' => ['!event', '!php'],
            ],
            'deprecation' => [
                'type' => 'stream',
                'path' => 'php://stdout',
                'channels' => ['php'],
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
            'sentry' => [
                'type' => 'service',
                'id' => Handler::class,
            ],
            'sentry_breadcrumbs' => [
                'type' => 'service',
                'id' => BreadcrumbHandler::class,
                'level' => 'info',
            ],
        ],
    ],
]);
