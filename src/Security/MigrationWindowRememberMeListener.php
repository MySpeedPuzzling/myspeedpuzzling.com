<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Auth0\Symfony\Security\Authenticator as Auth0Authenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\TokenDeauthenticatedEvent;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

/**
 * Drop-in replacement for Symfony's RememberMeListener, installed for the
 * duration of the Auth0 migration window (issue #147) by
 * ScopedRememberMeListenerPass. Behaviour is identical to core except for one
 * rule: a login failure coming from the Auth0 session authenticator does not
 * clear the remember-me cookie.
 *
 * Why this exists. Core subscribes `LoginFailureEvent => clearCookie` with no
 * event argument, so it cannot tell a real failed sign-in from bookkeeping
 * noise. The Auth0 session authenticator (Auth0\Symfony\Security\Authenticator)
 * answers `supports() === true` on every request and throws whenever the
 * session carries no Auth0 credentials - which is every anonymous request AND
 * every request from a natively logged-in user. With core's listener that
 * means:
 *
 *  - a REMEMBERME deletion cookie on every anonymous response, which makes
 *    AnonymousCacheHeadersSubscriber bail out and kills shared cacheability
 *    of anonymous pages (#164); and
 *  - the just-issued cookie of a native user being deleted again on their very
 *    next request - remember-me would never survive a single page view.
 *
 * This is what deferred remember-me to Phase 6 in the original migration plan.
 * Scoping the one failure branch lifts the block without touching the window
 * wiring: the Auth0 authenticator keeps refreshing legacy sessions, the
 * /login/auth0 escape hatch keeps working, and nobody is forced to sign out.
 *
 * Phase 6 removal: when the Auth0 authenticator leaves the main firewall,
 * delete this class together with ScopedRememberMeListenerPass and core's
 * listener takes over unchanged - no config edit needed beyond that.
 *
 * Deliberately stateless (readonly, no properties beyond its collaborators):
 * this service lives across requests in FrankenPHP worker mode, so it must
 * never hold per-request or per-user data. All cookie state is written into
 * the current Request's attributes by the handler, not kept here.
 */
final readonly class MigrationWindowRememberMeListener implements EventSubscriberInterface
{
    public function __construct(
        private RememberMeHandlerInterface $rememberMeHandler,
        private null|LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}|string>
     */
    public static function getSubscribedEvents(): array
    {
        // Priorities mirror core's RememberMeListener exactly: -64 leaves
        // CheckRememberMeConditionsListener (-32) room to enable the badge first.
        return [
            LoginSuccessEvent::class => ['onSuccessfulLogin', -64],
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'clearCookie',
            TokenDeauthenticatedEvent::class => 'clearCookie',
        ];
    }

    public function onSuccessfulLogin(LoginSuccessEvent $event): void
    {
        $passport = $event->getPassport();

        if (!$passport->hasBadge(RememberMeBadge::class)) {
            $this->logger?->debug('Remember me skipped: your authenticator does not support it.', [
                'authenticator' => $event->getAuthenticator()::class,
            ]);

            return;
        }

        // Make sure any old remember-me cookies are cancelled
        $this->rememberMeHandler->clearRememberMeCookie();

        $badge = $passport->getBadge(RememberMeBadge::class);
        assert($badge instanceof RememberMeBadge);

        if (!$badge->isEnabled()) {
            $this->logger?->debug('Remember me skipped: the RememberMeBadge is not enabled.');

            return;
        }

        $this->logger?->debug('Remember-me was requested; setting cookie.');

        $this->rememberMeHandler->createRememberMeCookie($event->getUser());
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        // The one deviation from core - see the class docblock. The Auth0
        // authenticator failing means "this session is not an Auth0 session",
        // not "somebody tried to sign in and got it wrong", so it must not
        // invalidate a perfectly good remember-me cookie.
        if ($event->getAuthenticator() instanceof Auth0Authenticator) {
            return;
        }

        $this->clearCookie();
    }

    public function clearCookie(): void
    {
        $this->rememberMeHandler->clearRememberMeCookie();
    }
}
