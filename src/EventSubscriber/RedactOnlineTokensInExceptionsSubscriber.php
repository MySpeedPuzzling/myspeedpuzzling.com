<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Services\Logging\OnlineTokenRedactor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The router answers a path it does not know with the whole URI in the exception's message ("No route found for
 * "GET …/api/compete/<token>/whatever""), and that message is logged and reported as it is. A 4xx whose message holds
 * a personal link's token (OnlineTokenRedactor) is replaced - same status, headers and, for the router's own two, the
 * same class (Sentry ignores those by class) - before anything logs it (Symfony's ErrorListener logs at priority 0).
 */
final readonly class RedactOnlineTokensInExceptionsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OnlineTokenRedactor $redactor,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 128],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof HttpExceptionInterface === false || $exception->getStatusCode() >= 500) {
            return;
        }

        $message = $exception->getMessage();
        $previous = $exception->getPrevious();
        $leaks = $this->redactor->containsToken($message) || ($previous !== null && $this->redactor->containsToken($previous->getMessage()));

        if ($leaks === false) {
            return;
        }

        $redacted = $this->redactor->redact($message);
        $headers = $exception->getHeaders();

        $event->setThrowable(match (true) {
            $exception instanceof NotFoundHttpException => new NotFoundHttpException($redacted, null, 0, $headers),
            $exception instanceof MethodNotAllowedHttpException => new MethodNotAllowedHttpException(
                self::allowedMethods($headers),
                $redacted,
                null,
                0,
                $headers,
            ),
            default => new HttpException($exception->getStatusCode(), $redacted, null, $headers),
        });
    }

    /**
     * @param array<mixed> $headers
     * @return list<string>
     */
    private static function allowedMethods(array $headers): array
    {
        $allow = $headers['Allow'] ?? '';

        return is_string($allow) && $allow !== '' ? array_map(trim(...), explode(',', $allow)) : [];
    }
}
