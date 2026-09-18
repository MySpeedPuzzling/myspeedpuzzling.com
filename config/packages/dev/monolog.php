<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sentry\Monolog\BreadcrumbHandler;
use Sentry\Monolog\ExceptionToSentryIssueHandler;
use Sentry\Monolog\LogToSentryIssueHandler;

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
            // Same split and channel filter as prod/monolog.php, which explains both
            'sentry' => [
                'type' => 'service',
                'id' => LogToSentryIssueHandler::class,
                'channels' => ['!sentry_sdk', '!messenger', '!php'],
            ],
            'sentry_exceptions' => [
                'type' => 'service',
                'id' => ExceptionToSentryIssueHandler::class,
                'channels' => ['!sentry_sdk', '!messenger', '!php'],
            ],
            'sentry_breadcrumbs' => [
                'type' => 'service',
                'id' => BreadcrumbHandler::class,
                'level' => 'info',
            ],
        ],
    ],
]);
