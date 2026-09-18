<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use SpeedPuzzling\Web\Security\SocialLoginAuthenticator;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Audit trail for sign-ins (issue #147, 2d): structured log lines for
 * login/logout on the main firewall, plus the user_account.last_login_at write.
 *
 * Failures are recorded only for the interactive sign-in authenticators (the
 * password form, the sign-in link, the social callbacks) - those are the attempts
 * the recent-activity page is about. A rejected remember-me cookie (expired,
 * password changed since) is not somebody trying to get in.
 *
 * On top of the Monolog lines, every event lands in the auth_audit_log table
 * (RecordAuthAuditEvent) - the queryable per-user history behind the
 * recent-activity page. The recorder swallows failures: a broken audit write
 * must never break login.
 */
final readonly class AuthenticationAuditSubscriber implements EventSubscriberInterface
{
    private const string MAIN_FIREWALL = 'main';

    public function __construct(
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private AuthAuditRecorder $authAuditRecorder,
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
        $request = $event->getRequest();

        $signInLinkUsed = $authenticator instanceof LoginLinkAuthenticator;

        $this->logger->info('Login succeeded.', [
            'user_id' => $user->getUserIdentifier(),
            'authenticator' => $authenticator::class,
            'login_link_used' => $signInLinkUsed,
        ]);

        if ($user instanceof UserAccount) {
            $user->recordLogin($this->clock->now());

            // Documented exception (D10) to the "flush only in the Messenger transaction
            // middleware" rule: this runs inside the security listener where no handler
            // transaction exists - without an immediate flush the timestamp never persists.
            $this->entityManager->flush();
        }

        $eventType = match (true) {
            $signInLinkUsed => AuthAuditEventType::SignInLinkUsed,
            $authenticator instanceof SocialLoginAuthenticator => AuthAuditEventType::OauthLogin,
            default => AuthAuditEventType::LoginSuccess,
        };

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: $eventType,
            userId: $user->getUserIdentifier(),
            email: $user instanceof UserAccount ? $user->email : null,
            authenticator: self::authenticatorLabel($authenticator),
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        ));
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getFirewallName() !== self::MAIN_FIREWALL) {
            return;
        }

        $authenticator = $event->getAuthenticator();

        if (
            !$authenticator instanceof LoginFormAuthenticator
            && !$authenticator instanceof LoginLinkAuthenticator
            && !$authenticator instanceof SocialLoginAuthenticator
        ) {
            return;
        }

        $request = $event->getRequest();
        $email = $request->hasSession()
            ? $request->getSession()->get(SecurityRequestAttributes::LAST_USERNAME)
            : null;

        // Info, not warning: a mistyped password is not a problem to be alerted about
        // (warnings become Sentry issues). Every failure is in auth_audit_log below.
        $this->logger->info('Login failed.', [
            'authenticator' => $authenticator::class,
            'email' => $email,
            'client_ip' => $request->getClientIp(),
            'exception' => $event->getException(),
        ]);

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::LoginFailure,
            email: is_string($email) ? $email : null,
            authenticator: self::authenticatorLabel($authenticator),
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            metadata: ['reason' => $event->getException()::class],
        ));
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

        $request = $event->getRequest();

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::Logout,
            userId: $token->getUserIdentifier(),
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        ));
    }

    private static function authenticatorLabel(AuthenticatorInterface $authenticator): string
    {
        return match (true) {
            $authenticator instanceof LoginFormAuthenticator => 'form',
            $authenticator instanceof LoginLinkAuthenticator => 'login_link',
            $authenticator instanceof SocialLoginAuthenticator => $authenticator->provider()->authenticatorLabel(),
            default => strtolower(substr(strrchr($authenticator::class, '\\') ?: $authenticator::class, 1)),
        };
    }
}
