<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Value\LiveEntryDeviceCookie;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Takes the live entry's device cookie (LiveEntryDeviceCookie, competition toolkit C3 - R §2.7) away when it no longer
 * names the person using the device: on sign-out, and when another player signs in (any way in - form, social, link,
 * code or the always-on remember-me; LoginSuccessEvent). The offline copy of the live entry page then shows nobody's
 * saved rounds until the new player opens the page online, which sets the cookie for them.
 *
 * A sign-in only costs a query on a device that carries the cookie (organisers and referees) - and the same player
 * signing in again keeps it. The live entry page itself, answering the very request that signed someone in
 * (remember-me), sets the cookie for the new player: that one is never cleared here.
 */
final readonly class LiveResultsDeviceCookieSubscriber implements EventSubscriberInterface
{
    private const string CLEAR_ATTRIBUTE = '_msp_live_player_clear';

    public function __construct(
        private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => ['onLoginSuccess', 0],
            KernelEvents::RESPONSE => ['onKernelResponse', 10],
            // After Symfony's DefaultLogoutListener (64) has set the redirect response
            LogoutEvent::class => ['onLogout', -64],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->cookies->has(LiveEntryDeviceCookie::NAME)) {
            return;
        }

        $cookiePlayerId = LiveEntryDeviceCookie::playerOf($request);
        $user = $event->getUser();

        if ($cookiePlayerId !== null && $user instanceof UserAccount) {
            try {
                if ($this->getPlayerProfile->byUserId($user->getUserIdentifier())->playerId === $cookiePlayerId) {
                    return;
                }
            } catch (PlayerNotFound) {
                // A brand-new account without its player yet - certainly not the cookie's player
            }
        }

        $request->attributes->set(self::CLEAR_ATTRIBUTE, true);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->attributes->get(self::CLEAR_ATTRIBUTE) !== true) {
            return;
        }

        $response = $event->getResponse();

        if (!LiveEntryDeviceCookie::sets($response)) {
            LiveEntryDeviceCookie::clear($request, $response);
        }
    }

    /**
     * Signing out ends it whatever the request carried (expiring a cookie the browser does not have is harmless)
     */
    public function onLogout(LogoutEvent $event): void
    {
        $response = $event->getResponse();

        if ($response === null) {
            return;
        }

        LiveEntryDeviceCookie::clear($event->getRequest(), $response);
    }
}
