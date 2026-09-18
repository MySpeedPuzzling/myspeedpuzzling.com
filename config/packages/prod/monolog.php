<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sentry\Monolog\BreadcrumbHandler;
use Sentry\Monolog\ExceptionToSentryIssueHandler;
use Sentry\Monolog\LogToSentryIssueHandler;

// Channels whose problems reach Sentry by another route. Sending their log records
// as well would report every problem twice:
// - sentry_sdk: SDK send failures - capturing them would loop ("failed to send"
//   events sent through the failing transport)
// - messenger: worker failures are captured by the bundle's MessengerListener, which
//   also honours `capture_soft_fails: false` - the retry warnings logged here would
//   defeat that
// - php: PHP errors and warnings are captured by the SDK's own error handler
$sentryExcludedChannels = ['!sentry_sdk', '!messenger', '!php'];

return App::config([
    'monolog' => [
        'handlers' => [
            'main' => [
                'type' => 'fingers_crossed',
                'action_level' => 'warning',
                'handler' => 'nested',
                'excluded_http_codes' => [404, 405],
                'buffer_size' => 50,
                'channels' => ['!sentry_sdk'],
            ],
            'nested' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'debug',
                'formatter' => 'monolog.formatter.json',
            ],
            // Warning and above become Sentry issues. The two handlers split the work:
            // sentry = log messages, sentry_exceptions = records carrying an exception.
            // Each skips what the other takes - drop either and a whole class of
            // problems silently stops reaching Sentry (as it did 2026-07-12 → 09-18).
            'sentry' => [
                'type' => 'service',
                'id' => LogToSentryIssueHandler::class,
                'channels' => $sentryExcludedChannels,
            ],
            'sentry_exceptions' => [
                'type' => 'service',
                'id' => ExceptionToSentryIssueHandler::class,
                'channels' => $sentryExcludedChannels,
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
            // SDK send failures land in stderr logs only
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
