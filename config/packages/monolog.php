<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'monolog' => [
        // Dedicated channel for Sentry SDK internals (send failures etc.) so they
        // never loop back into Sentry as events — see prod/monolog.php handlers.
        // object_storage: AsyncAws' per-request log of the S3 client and its
        // retries (config/services.php)
        // internal_api_audit: one line per write through the internal admin API
        // (InternalApiAuditSubscriber) - kept apart so production writes it on its own
        // service_worker: navigations the service worker had to ask for twice
        // (NavigationFetchFailureController) - info lines meant to be counted
        'channels' => ['sentry_sdk', 'object_storage', 'internal_api_audit', 'service_worker'],
    ],
]);
