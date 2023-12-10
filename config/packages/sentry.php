<?php declare(strict_types=1);

use Symfony\Config\SentryConfig;

return static function (SentryConfig $sentryConfig) {
    $sentryConfig->dsn('%env(SENTRY_DSN)%');

    $sentryConfig->tracing()
        ->enabled(false);

    $sentryConfig->registerErrorListener(false);

    $sentryConfig->messenger()
        ->enabled(true)
        ->captureSoftFails(true);

    $sentryConfig->options()
        ->environment('%kernel.environment%')
        ->sendDefaultPii(true)
        ->ignoreExceptions([
            Symfony\Component\Security\Core\Exception\AccessDeniedException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        ]);
};
