<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every refusal under the participant API (`/api/compete/…`, docs/features/online-competitions/contracts/phase-1.md
 * §6) is JSON - also the ones no controller answered itself: a path or method that does not exist (`not_found` 404,
 * `method_not_allowed` 405 with `Allow`), a link that opens nothing (`unknown_link`), a 429 with its `Retry-After`. The
 * phones' outboxes read the `error` code; an HTML error page would look like a proxy in the way.
 *
 * Runs after the listeners that log the exception and before Symfony renders the error page (-128), like
 * InternalApiErrorResponseSubscriber. A server error is left to Symfony - it is a bug, and stays visible in Sentry.
 */
final readonly class CompeteApiErrorResponseSubscriber implements EventSubscriberInterface
{
    public const string PATH_PREFIX = '/api/compete/';

    public function __construct(
        private OnlineParticipantApi $api,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -64],
        ];
    }

    public static function isCompeteApiRequest(Request $request): bool
    {
        // Decoded, like the firewall matches the path
        return str_starts_with(rawurldecode($request->getPathInfo()), self::PATH_PREFIX);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (self::isCompeteApiRequest($event->getRequest()) === false) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof HttpExceptionInterface === false || $exception->getStatusCode() >= 500) {
            return;
        }

        $status = $exception->getStatusCode();
        $error = match (true) {
            $exception instanceof OnlineLinkNotFound => 'unknown_link',
            $status === Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
            default => strtolower(str_replace([' ', '-', "'"], ['_', '_', ''], Response::$statusTexts[$status] ?? 'error')),
        };

        /** @var array<string, string> $headers */
        $headers = array_filter($exception->getHeaders(), is_string(...));

        $event->setResponse($this->api->error($error, $status, $headers));
    }
}
