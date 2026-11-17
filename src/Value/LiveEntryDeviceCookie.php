<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `msp_live_player=<playerId>`: which player last used the live result entry on this device (competition toolkit C3,
 * docs/features/competitions-management/toolkit/C-judge-page.md §1, R §2.7). The offline copy of the page (the shell,
 * LiveResultsShellController) holds nothing of anybody - it reads this cookie to know whose saved rounds it may show.
 *
 * Not a credential and nothing the server trusts: readable by the page's JS (not HttpOnly), set only by the live entry
 * page (organisers and the event's referees - nobody else ever gets it), cleared on sign-out and when another player
 * signs in on the device (LiveResultsDeviceCookieSubscriber). Lives as long as the always-on sign-in.
 */
final class LiveEntryDeviceCookie
{
    public const string NAME = 'msp_live_player';

    public static function forPlayer(string $playerId, Request $request, int $lifetimeSeconds): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue($playerId)
            // @phpstan-ignore clock.systemTime (cookie expiry: the browser judges it on its own real clock)
            ->withExpires(time() + $lifetimeSeconds)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    /**
     * The player the device's cookie names, null without one (or with a value that is no id)
     */
    public static function playerOf(Request $request): null|string
    {
        $value = $request->cookies->get(self::NAME);

        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? strtolower($value) : null;
    }

    public static function sets(Response $response): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::NAME && $cookie->getPath() === '/') {
                return true;
            }
        }

        return false;
    }

    /**
     * Same name, path, secure, httponly and samesite as the set cookie, with an expiry in the past
     */
    public static function clear(Request $request, Response $response): void
    {
        $response->headers->clearCookie(
            self::NAME,
            path: '/',
            secure: $request->isSecure(),
            httpOnly: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
