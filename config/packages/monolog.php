<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'monolog' => [
        // Dedicated channel for Sentry SDK internals (send failures etc.) so they
        // never loop back into Sentry as events — see prod/monolog.php handlers.
        // object_storage: AsyncAws' per-request log of the S3 client and its
        // retries (config/services.php)
        'channels' => ['sentry_sdk', 'object_storage'],
    ],
]);
