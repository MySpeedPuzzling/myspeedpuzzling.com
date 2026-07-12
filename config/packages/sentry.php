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
    foreach (glob($dir . '*.php') ?: [] as $file) {
        $classSerializers[$namespace . basename($file, '.php')] = GenericObjectSerializer::class;
    }
}

return App::config([
    'sentry' => [
        'dsn' => '%env(SENTRY_DSN)%',
        'tracing' => [
            'enabled' => true,
            'console' => [
                'excluded_commands' => [
                    'messenger:consume',
                    'myspeedpuzzling:recalculate-puzzle-intelligence',
                ],
            ],
            'dbal' => [
                // PREPARE spans are noise and inflate transaction envelopes
                'ignore_prepare_spans' => true,
            ],
        ],
        'register_error_listener' => false,
        // SDK errors (like HTTP send failures) go to a dedicated channel: visible in
        // stderr logs, but never captured back into Sentry as events
        'logger' => 'monolog.logger.sentry_sdk',
        'messenger' => [
            'enabled' => true,
            // Only report messages that exhausted all retries — retryable failures
            // (e.g. transient SMTP timeouts) recover via the async retry strategy
            'capture_soft_fails' => false,
            // Fresh runtime context (scope, logs, metrics) per consumed message —
            // the consumer is a long-running process, same leak risk as worker mode
            'isolate_context_by_message' => true,
        ],
        'options' => [
            'environment' => '%kernel.environment%',
            'send_default_pii' => true,
            // Only continue distributed traces whose baggage carries our org id —
            // forged/replayed sentry-trace headers from bots must not join our traces
            'org_id' => 4506172941991936,
            'strict_trace_continuation' => true,
            // Defaults (2s connect / 5s total) are too tight for envelopes carrying
            // profiles — sends happen post-response in kernel.terminate, so this
            // does not affect user-facing latency
            'http_connect_timeout' => 5,
            'http_timeout' => 15,
            'ignore_exceptions' => [
                AccessDeniedException::class,
                NotFoundHttpException::class,
            ],
            'traces_sampler' => 'sentry.traces_sampler',
            // Relative to traced requests (traces_sampler decides what is traced)
            'profiles_sample_rate' => '%env(float:SENTRY_PROFILES_SAMPLE_RATE)%',
            'ignore_transactions' => [
                // Symfony profiler/debug toolbar routes
                '*/_wdt*',
                '*/_profiler*',
            ],
            'class_serializers' => $classSerializers,
        ],
    ],
]);
