<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Services\Sentry\GenericObjectSerializer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

// Auto-discover FormData and Message classes for rich Sentry serialization
$classSerializers = [];
$namespaceDirs = [
    'SpeedPuzzling\\Web\\FormData\\' => __DIR__ . '/../../src/FormData/',
    'SpeedPuzzling\\Web\\Message\\' => __DIR__ . '/../../src/Message/',
];

foreach ($namespaceDirs as $namespace => $dir) {
    foreach (glob($dir . '*.php') as $file) {
        $classSerializers[$namespace . basename($file, '.php')] = GenericObjectSerializer::class;
    }
}

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
            'class_serializers' => $classSerializers,
        ],
    ],
]);
