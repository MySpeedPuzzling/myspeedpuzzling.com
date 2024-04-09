<?php declare(strict_types=1);

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Config\SentryConfig;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (SentryConfig $sentryConfig) {
    $sentryConfig->dsn(env('SENTRY_DSN'));

    $sentryConfig->tracing()
        ->enabled(true);

    $sentryConfig->registerErrorListener(false);

    $sentryConfig->messenger()
        ->enabled(true)
        ->captureSoftFails(true);

    $sentryConfig->options()
        ->environment(param('kernel.environment'))
        ->sendDefaultPii(true)
        ->ignoreExceptions([
            AccessDeniedException::class,
            NotFoundHttpException::class,
        ])
    ->tracesSampleRate(env('SENTRY_TRACES_SAMPLE_RATE')->float())
    ->profilesSampleRate(env('SENTRY_PROFILES_SAMPLE_RATE')->float());
};
