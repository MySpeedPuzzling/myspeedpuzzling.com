<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Sentry\BeforeSendTransaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

return App::config([
    'sentry' => [
        'dsn' => '%env(SENTRY_DSN)%',
        'tracing' => [
            'enabled' => true,
        ],
        'register_error_listener' => false,
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
            // Debug logging for transaction investigation
            'before_send_transaction' => BeforeSendTransaction::class,
        ],
    ],
]);
