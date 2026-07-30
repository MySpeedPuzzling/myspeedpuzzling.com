<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Audit trail for the native auth stack (issue #147, 2d): structured log lines
 * for login/logout on the main firewall, plus the user_account.last_login_at
 * write that drives the Phase 5 migration metrics ("has native activity" also
 * guards applyAuth0Import against stale re-imports regressing an account).
 *
 * Failures are logged only for the allowlisted native authenticators: during
 * window A the Auth0 authenticator fails on every anonymous request by design,
 * which would turn plain browsing into a warning flood.
 */
final readonly class AuthenticationAuditSubscriber implements EventSubscriberInterface
{
    private const string MAIN_FIREWALL = 'main';

    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== self::MAIN_FIREWALL) {
            return;
        }

        $user = $event->getUser();
        $authenticator = $event->getAuthenticator();

        $this->logger->info('Login succeeded.', [
            'user_id' => $user->getUserIdentifier(),
            'authenticator' => $authenticator::class,
            // Phase 5 exit-metric counter (grep/Sentry-aggregatable)
            'login_link_used' => $authenticator instanceof LoginLinkAuthenticator,
        ]);

        if ($user instanceof UserAccount) {
            $user->recordLogin($this->clock->now());

            // Documented exception (D10) to the "flush only in the Messenger transaction
            // middleware" rule: this runs inside the security listener where no handler
            // transaction exists - without an immediate flush the timestamp never persists.
            $this->entityManager->flush();
        }
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getFirewallName() !== self::MAIN_FIREWALL) {
            return;
        }

        $authenticator = $event->getAuthenticator();

        if (!$authenticator instanceof LoginFormAuthenticator && !$authenticator instanceof LoginLinkAuthenticator) {
            return;
        }

        $request = $event->getRequest();

        $this->logger->warning('Login failed.', [
            'authenticator' => $authenticator::class,
            'email' => $request->hasSession()
                ? $request->getSession()->get(SecurityRequestAttributes::LAST_USERNAME)
                : null,
            'client_ip' => $request->getClientIp(),
            'exception' => $event->getException(),
        ]);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();

        if ($token === null) {
            return;
        }

        $this->logger->info('Logout.', [
            'user_id' => $token->getUserIdentifier(),
        ]);
    }
}
