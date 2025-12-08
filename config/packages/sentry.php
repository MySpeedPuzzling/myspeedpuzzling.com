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
        'register_error_listener' => true,
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
            'traces_sample_rate' => '%env(float:SENTRY_TRACES_SAMPLE_RATE)%',
            'profiles_sample_rate' => '%env(float:SENTRY_PROFILES_SAMPLE_RATE)%',
            'ignore_transactions' => [
                // Symfony profiler/debug toolbar routes
                '*/_wdt*',
                '*/_profiler*',
            ],
        ],
    ],
]);
