<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

return App::config([
    'sentry' => [
        'dsn' => '%env(SENTRY_DSN)%',
        'tracing' => [
            'enabled' => true,
        ],
        'register_error_listener' => false,
        // Use Monolog logger so Sentry SDK errors (like HTTP failures) are logged instead of silently swallowed
        'logger' => 'monolog.logger',
        'messenger' => [
            'enabled' => true,
            'capture_soft_fails' => true,
        ],
        'options' => [
            'environment' => '%kernel.environment%',
            'send_default_pii' => true,
            'ignore_exceptions' => [
                AccessDeniedException::class,
                NotFoundHttpException::class,
            ],
            'traces_sampler' => 'sentry.traces_sampler',
            'profiles_sample_rate' => 1.0, // Profile all traced requests (sampling controlled by traces_sampler)
            'ignore_transactions' => [
                // Symfony profiler/debug toolbar routes
                '*/_wdt*',
                '*/_profiler*',
            ],
        ],
    ],
]);
