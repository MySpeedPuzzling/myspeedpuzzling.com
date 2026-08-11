<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Security\MigrationWindowAuth0Authenticator;
use SpeedPuzzling\Web\Security\MigrationWindowRememberMeListener;
use SpeedPuzzling\Web\Tests\TestDouble\FakeInteractiveAuthenticator;
use SpeedPuzzling\Web\Tests\TestDouble\RecordingRememberMeHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\TokenDeauthenticatedEvent;

/**
 * The one behaviour that separates our listener from Symfony's: which login
 * failures are allowed to delete a remember-me cookie. Everything else is a
 * verbatim copy of core and is covered functionally by RememberMeTest.
 *
 * A KernelTestCase because Auth0's Authenticator is final and cannot be
 * doubled - the real service from the container is the only way to produce an
 * instance the listener's instanceof check will recognise.
 */
final class MigrationWindowRememberMeListenerTest extends KernelTestCase
{
    public function testAuth0AuthenticatorFailureDoesNotClearTheCookie(): void
    {
        self::bootKernel();

        // The real Auth0\Symfony\Security\Authenticator - it is final, so the
        // container is the only source of an instance the listener recognises
        $auth0Authenticator = self::getContainer()->get('auth0.authenticator');

        $handler = new RecordingRememberMeHandler();
        $listener = new MigrationWindowRememberMeListener($handler);

        $listener->onLoginFailure($this->failureFor($auth0Authenticator));

        // Every anonymous request and every request from a natively logged-in user
        // produces exactly this failure - clearing here would put a REMEMBERME
        // deletion cookie on all of them
        self::assertSame(0, $handler->clearCalls);
    }

    /**
     * The firewall runs the wrapper, not the bundle authenticator, so it is the
     * wrapper that reaches this listener. Missing it here is not a subtle
     * regression: it puts a deletion cookie on every anonymous response again.
     */
    public function testWrappedAuth0AuthenticatorFailureDoesNotClearTheCookieEither(): void
    {
        self::bootKernel();

        $wrapped = self::getContainer()->get(MigrationWindowAuth0Authenticator::class);

        $handler = new RecordingRememberMeHandler();
        $listener = new MigrationWindowRememberMeListener($handler);

        $listener->onLoginFailure($this->failureFor($wrapped));

        self::assertSame(0, $handler->clearCalls);
    }

    public function testRealLoginFailureStillClearsTheCookie(): void
    {
        $handler = new RecordingRememberMeHandler();
        $listener = new MigrationWindowRememberMeListener($handler);

        $listener->onLoginFailure($this->failureFor(new FakeInteractiveAuthenticator()));

        self::assertSame(1, $handler->clearCalls);
    }

    public function testLogoutClearsTheCookie(): void
    {
        $handler = new RecordingRememberMeHandler();
        $listener = new MigrationWindowRememberMeListener($handler);

        $listener->clearCookie();

        self::assertSame(1, $handler->clearCalls);
    }

    public function testSubscribedEventsMatchTheCoreListener(): void
    {
        $events = MigrationWindowRememberMeListener::getSubscribedEvents();

        // Same set and same priorities as Symfony's RememberMeListener - only the
        // LoginFailureEvent handler is ours, and it must keep receiving the event
        // object (core subscribes a no-argument method, which is the whole problem)
        self::assertSame(
            [LoginSuccessEvent::class, LoginFailureEvent::class, LogoutEvent::class, TokenDeauthenticatedEvent::class],
            array_keys($events),
        );
        self::assertSame(['onSuccessfulLogin', -64], $events[LoginSuccessEvent::class]);
        self::assertSame('onLoginFailure', $events[LoginFailureEvent::class]);
        self::assertSame('clearCookie', $events[LogoutEvent::class]);
        self::assertSame('clearCookie', $events[TokenDeauthenticatedEvent::class]);
    }

    private function failureFor(AuthenticatorInterface $authenticator): LoginFailureEvent
    {
        return new LoginFailureEvent(
            new AuthenticationException('No Auth0 session was found.'),
            $authenticator,
            new Request(),
            null,
            'main',
        );
    }
}
