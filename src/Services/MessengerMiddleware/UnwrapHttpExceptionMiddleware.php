<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MessengerMiddleware;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/**
 * Rethrows a handler's HTTP-flavoured exception instead of Messenger's wrapper.
 *
 * WHY THIS EXISTS. Domain exceptions like `LentPuzzleNotFound` extend
 * `NotFoundHttpException` precisely so they render as 404. Thrown inside a
 * message handler they never got the chance: Messenger wraps every handler
 * failure in `HandlerFailedException`, which is NOT an `HttpExceptionInterface`,
 * so Symfony fell through to a **500**. Two consequences, both observed in
 * production on 2026-08-02:
 *
 *  1. A user clicking "return puzzle" on a puzzle that had just been passed to
 *     someone else got a crash page instead of "not found" (route
 *     `return_puzzle`, twice, a real member retrying).
 *  2. Those 500s were INVISIBLE IN SENTRY. `ignore_exceptions` lists
 *     `NotFoundHttpException`, and the SDK's matcher walks the WHOLE exception
 *     chain (`Client::applyIgnoreOptions()` → `is_a()` hierarchy check),
 *     discarding the entire event because a nested exception matched — even
 *     though the outer one produced a 500. Sentry recorded zero error events in
 *     the week this was happening.
 *
 * Nine domain exception classes extending `NotFoundHttpException` /
 * `AccessDeniedException` are thrown across 24 handlers, so this was not one
 * route misbehaving — every one of them produced an invisible 500.
 *
 * WHY A MIDDLEWARE, NOT UNWRAPPING AT THE DISPATCH SITES. There are 171
 * `dispatch()` calls across 135 controllers/components. Per-site unwrapping
 * makes the safety property depend on nobody ever forgetting: dispatch #172
 * silently reintroduces the invisible 500. One place cannot be forgotten — and
 * it makes controllers NICER, because they can now `catch (LentPuzzleNotFound)`
 * directly instead of catching the wrapper and digging through `getPrevious()`.
 *
 * DELIBERATELY SURGICAL. Only exceptions Symfony already knows how to render are
 * unwrapped — `HttpExceptionInterface` (status carried on the exception) and
 * `AccessDeniedException` (turned into 403/login-redirect by the security
 * listener, which never sees it while wrapped). Both are also the two entries in
 * Sentry's `ignore_exceptions`, i.e. exactly the classes whose wrapping produces
 * an INVISIBLE 500. Nothing throws an AccessDeniedException from a handler today;
 * it is covered so the trap cannot be walked into later.
 *
 * Anything else keeps its `HandlerFailedException`, so:
 *  - genuine bugs still surface as 500 AND stay fully visible in Sentry;
 *  - Messenger's retry/backoff machinery, which inspects the wrapper on the
 *    async transports, is untouched.
 *
 * Registered OUTERMOST on `command_bus` (first in the middleware list) so it
 * sees the wrapper after `doctrine_transaction` has rolled back — unwrapping
 * happens strictly after transaction handling, never instead of it.
 */
final readonly class UnwrapHttpExceptionMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $exception) {
            throw self::findHttpException($exception) ?? $exception;
        }
    }

    /**
     * Walks the nested exceptions for one Symfony can render itself.
     *
     * `HandlerFailedException` can hold several failures when a message has
     * multiple handlers; the first HTTP-flavoured one wins, which matches how
     * Symfony would have rendered it had the exception not been wrapped.
     */
    private static function findHttpException(HandlerFailedException $exception): null|Throwable
    {
        foreach ($exception->getWrappedExceptions() as $wrapped) {
            if ($wrapped instanceof HttpExceptionInterface || $wrapped instanceof AccessDeniedException) {
                return $wrapped;
            }

            // A handler may itself dispatch another message (events are handled
            // synchronously here), so the HTTP exception can sit one wrapper deeper.
            if ($wrapped instanceof HandlerFailedException) {
                $nested = self::findHttpException($wrapped);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
