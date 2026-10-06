<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\Security\InternalApiAuthenticator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every refusal of the internal API is JSON: `{"error": "…"}` (plus `"errors": {"field": "…"}` for invalid fields,
 * `"revealedPuzzles": […]` for a change that would reveal secret puzzles),
 * with the status of the HTTP exception - a 400, 401, 404 or 409 thrown by a controller, a handler (unwrapped by
 * UnwrapHttpExceptionMiddleware) or the firewall. Without this, Symfony renders its HTML error page for the
 * API's only consumers, scripts and Claude Code, which do not send `Accept: application/json`.
 *
 * Runs after the listeners that log the exception (priority 0) and after the firewall turned a missing token into a
 * 401 (priority 1), before Symfony renders the error page (-128). Anything else - a bug, any 5xx - is left to Symfony,
 * so it reaches Sentry as it always did. The path is matched decoded, like the firewall matches it
 * (InternalApiAuthenticator::isInternalApiRequest()).
 */
final readonly class InternalApiErrorResponseSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -64],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (InternalApiAuthenticator::isInternalApiRequest($event->getRequest()) === false) {
            return;
        }

        $exception = $event->getThrowable();

        // A server error is a bug however it was thrown - Symfony's own handling keeps it visible
        if ($exception instanceof HttpExceptionInterface === false || $exception->getStatusCode() >= 500) {
            return;
        }

        $status = $exception->getStatusCode();
        $message = $exception->getMessage() !== '' ? $exception->getMessage() : (Response::$statusTexts[$status] ?? 'Error');
        $body = ['error' => $message];

        // What a "confirmReveal": true would let out, so the caller can tell whether to send it
        if ($exception instanceof SecretPuzzlesWouldBeRevealed) {
            $body['revealedPuzzles'] = $exception->toArray();
        }

        if ($exception instanceof InternalApiInvalidInput) {
            $body['errors'] = $exception->errors;
        }

        $event->setResponse(new JsonResponse($body, $status, $exception->getHeaders()));
    }
}
