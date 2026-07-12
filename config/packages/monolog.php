<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'monolog' => [
        // Dedicated channel for Sentry SDK internals (send failures etc.) so they
        // never loop back into Sentry as events — see prod/monolog.php handlers
        'channels' => ['sentry_sdk'],
    ],
]);
