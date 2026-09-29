<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * "This browser has just signed in with its code" - for the duplicate submit.
 *
 * iOS fills the code from Mail and may submit the form itself while our
 * sixth-digit submit fires too (production, 2026-09-30): two POSTs sent with
 * the same, pre-login session cookie. The first signs in and migrates the
 * session (the old id is destroyed), so the second finds nothing pending and
 * used to fail - an error page, a sign_in_code_failed row, and the
 * LoginFailureEvent cleared the fresh remember-me cookie on the way.
 *
 * The success remembers where it went for 60 s, keyed by a hash of the session
 * id the request came with. That id is dead after the migration and only the
 * browser that sent it holds it, so the marker helps exactly that browser - and
 * it only ever answers with the same redirect; it never signs anybody in (the
 * target page does that with the cookies the first answer set).
 *
 * Cache, not session: the session behind that id is gone by the time the
 * duplicate arrives.
 */
final readonly class SignInCodeCompletion
{
    private const int TTL_SECONDS = 60;

    public function __construct(
        private CacheItemPoolInterface $signInCodeCompletionCache,
    ) {
    }

    public function remember(Request $request, string $target): void
    {
        $key = $this->key($request);

        if ($key === null) {
            return;
        }

        $item = $this->signInCodeCompletionCache->getItem($key);
        $item->set($target);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->signInCodeCompletionCache->save($item);
    }

    /**
     * Where the sign-in this browser just completed went, or null.
     */
    public function recall(Request $request): null|string
    {
        $key = $this->key($request);

        if ($key === null) {
            return null;
        }

        $target = $this->signInCodeCompletionCache->getItem($key)->get();

        return is_string($target) ? $target : null;
    }

    private function key(Request $request): null|string
    {
        if (!$request->hasSession()) {
            return null;
        }

        // The cookie the request came with - never the (possibly migrated) live id
        $sessionId = $request->cookies->get($request->getSession()->getName());

        if (!is_string($sessionId) || $sessionId === '') {
            return null;
        }

        return 'sign_in_code_done.' . hash('sha256', $sessionId);
    }
}
