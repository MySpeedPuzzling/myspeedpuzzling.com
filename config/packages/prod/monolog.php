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
// - object_storage: AsyncAws logs every failed S3 request at error level, then throws.
//   The caller reports what the failure means - a spooled upload, a missing result
//   image, an uncaught exception - so the same problem would land twice, and a
//   failure the caller handles by design would page as an error
// - navigation: click and history counts from the browser (NavClickController) - info lines
//   written by their own handler below; nothing on this channel is ever a problem to look at
$sentryExcludedChannels = ['!sentry_sdk', '!messenger', '!php', '!object_storage', '!navigation'];

return App::config([
    'monolog' => [
        'handlers' => [
            'main' => [
                'type' => 'fingers_crossed',
                'action_level' => 'warning',
                'handler' => 'nested',
                'excluded_http_codes' => [404, 405],
                'buffer_size' => 50,
                // internal_api_audit, service_worker and navigation have their own handlers below
                'channels' => ['!sentry_sdk', '!internal_api_audit', '!service_worker', '!navigation'],
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
            // The audit trail of the internal admin API (InternalApiAuditSubscriber): info records,
            // which fingers_crossed above would drop for a request without a warning - written
            // as they come. Info never becomes a Sentry issue, so it needs no exclusion there.
            'internal_api_audit' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'info',
                'channels' => ['internal_api_audit'],
                'formatter' => 'monolog.formatter.json',
            ],
            // Navigations the service worker had to ask for twice
            // (NavigationFetchFailureController): info lines to count in Loki, written
            // as they come. Its warnings (an offline page shown to somebody online)
            // still reach Sentry through the handlers above.
            'service_worker' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'info',
                'channels' => ['service_worker'],
                'formatter' => 'monolog.formatter.json',
            ],
            // Navigation instrumentation (NavClickController, docs/features/navigation P0-03): one info
            // line per chrome click, per session's viewport and per Back/Forward navigation, written as
            // they come and counted in Loki. Excluded from main and from both Sentry issue handlers.
            'navigation' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'info',
                'channels' => ['navigation'],
                'formatter' => 'monolog.formatter.json',
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
