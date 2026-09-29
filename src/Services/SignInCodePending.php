<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which sign-in this browser is waiting for (auth UX redesign phase 2).
 *
 * The 6-digit code only works in the browser that asked for it - that is the
 * point: in Instagram's in-app browser the e-mailed link opens the phone's own
 * browser, the code signs in the tab the visitor is actually in. The binding
 * is the session (it already exists on this path - the request POST writes the
 * CheckEmailFlash): the request id and the address live in it, never in a URL.
 *
 * Written for unknown addresses too, with an id no row will ever carry, so the
 * screen and the code check behave the same for them (D8). Wrong codes for such
 * a phantom are counted here, so the "N tries left" countdown matches too.
 *
 * Replaced by every new request ("each new code replaces the old one" - in this
 * browser; the older mail's link keeps working), dropped on success, on
 * lock-out and when it expires with the link.
 */
final readonly class SignInCodePending
{
    private const string SESSION_KEY = 'auth_sign_in_code_pending';

    public function __construct(
        private ClockInterface $clock,
        private int $signInLinkLifetimeSeconds,
    ) {
    }

    public function start(Request $request, UuidInterface $requestId, string $email): void
    {
        $request->getSession()->set(self::SESSION_KEY, [
            'request_id' => $requestId->toString(),
            'email' => $email,
            'expires_at' => $this->clock->now()->getTimestamp() + $this->signInLinkLifetimeSeconds,
            'misses' => 0,
        ]);
    }

    /**
     * @return null|array{request_id: UuidInterface, email: string, misses: int}
     */
    public function get(Request $request): null|array
    {
        // Never start a session just to find out there is nothing (#164)
        if (!$request->hasPreviousSession()) {
            return null;
        }

        $pending = $request->getSession()->get(self::SESSION_KEY);

        if (
            !is_array($pending)
            || !is_string($pending['request_id'] ?? null)
            || !Uuid::isValid($pending['request_id'])
            || !is_string($pending['email'] ?? null)
            || !is_int($pending['expires_at'] ?? null)
        ) {
            return null;
        }

        if ($pending['expires_at'] <= $this->clock->now()->getTimestamp()) {
            $this->clear($request);

            return null;
        }

        return [
            'request_id' => Uuid::fromString($pending['request_id']),
            'email' => $pending['email'],
            'misses' => is_int($pending['misses'] ?? null) ? $pending['misses'] : 0,
        ];
    }

    /**
     * A wrong code for a request without a row (unknown address): counted here
     * so the countdown looks exactly like a real one. Returns the new count.
     */
    public function recordMiss(Request $request): int
    {
        $pending = $request->getSession()->get(self::SESSION_KEY);

        if (!is_array($pending)) {
            return 0;
        }

        $misses = (is_int($pending['misses'] ?? null) ? $pending['misses'] : 0) + 1;
        $pending['misses'] = $misses;
        $request->getSession()->set(self::SESSION_KEY, $pending);

        return $misses;
    }

    public function clear(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }
}
