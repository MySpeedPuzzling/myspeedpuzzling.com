<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * The single password authenticator for the native auth stack (issue #147).
 * One authenticator, two credential branches: accounts with a local hash verify
 * via PasswordCredentials (bcrypt->argon2id migrate_from rehash included);
 * imported Auth0 accounts without a local hash fall back to the trickle
 * verifier once, then adopt the verified password locally.
 *
 * A single authenticator is load-bearing: Symfony runs authenticators
 * sequentially and the first AuthenticationException short-circuits, so a
 * fallback chain between two authenticators would never fire (README §Auth
 * features at launch).
 */
final class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    // Error messages double as translation keys, rendered on the login page
    // via error.messageKey|trans({}, 'security') - translated in the 2c slice
    public const string ERROR_PASSWORD_LEAKED = 'This password was found in a public data breach. To protect your account, please reset your password to sign in.';
    public const string ERROR_TEMPORARILY_UNAVAILABLE = 'Sign-in is temporarily unavailable. Please try again in a moment.';

    public function __construct(
        private readonly UserAccountRepository $userAccountRepository,
        private readonly UserAccountProvider $userAccountProvider,
        private readonly TricklePasswordVerifier $tricklePasswordVerifier,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RateLimiterFactoryInterface $loginEmailIpLimiter,
        private readonly RateLimiterFactoryInterface $loginIpLimiter,
        private readonly LoggerInterface $logger,
        private readonly bool $auth0TrickleLoginEnabled,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = trim((string) $request->request->get('email'));
        $password = (string) $request->request->get('password');
        $csrfToken = (string) $request->request->get('_csrf_token');

        if ($request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);
        }

        if ($email === '' || $password === '') {
            throw new BadCredentialsException('Empty email or password.');
        }

        $this->throttle($email, $request->getClientIp());

        $userAccount = $this->userAccountRepository->findByEmail($email);

        // Badge identifier is the typed email; the authenticated user's identifier
        // stays the user_id string (UserAccount::getUserIdentifier()). A null user
        // makes the badge throw UserNotFoundException, which Symfony hides as
        // BadCredentialsException - unknown email and wrong password are
        // indistinguishable to the client (anti-enumeration).
        $userBadge = new UserBadge($email, static fn (): null|UserAccount => $userAccount);

        $credentials = $this->shouldVerifyViaTrickle($userAccount)
            ? new CustomCredentials($this->verifyViaTrickle($request->getClientIp()), $password)
            : new PasswordCredentials($password);

        return new Passport($userBadge, $credentials, [
            // Stateless token id, already listed in config/packages/csrf.php -
            // validating it must not start a session (anonymous-cacheability constraint)
            new CsrfTokenBadge('authenticate', $csrfToken),
            new RememberMeBadge(),
            // Transparent bcrypt->argon2id rehash on successful local verification.
            // The upgrader must be explicit: without it PasswordMigratingListener
            // reflects on the UserBadge loader's bound $this - our static closure
            // has none, and the rehash would silently never happen.
            new PasswordUpgradeBadge($password, $this->userAccountProvider),
        ]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        // A successful login clears the per-account counter so a legitimate user
        // is not locked out by their own earlier typos (mirrors login_throttling)
        $email = trim((string) $request->request->get('email'));
        $this->loginEmailIpLimiter->create($this->emailIpKey($email, $request->getClientIp()))->reset();

        $session = $request->getSession();
        $targetPath = $this->getTargetPath($session, $firewallName);

        if ($targetPath !== null) {
            $this->removeTargetPath($session, $firewallName);

            return new RedirectResponse($targetPath);
        }

        // Migration-window glue: while the Auth0 authenticator is wired, its failure
        // handler preempts the ExceptionListener on protected pages (it redirects to
        // /login itself), so the deep-link target lands in its session key and
        // TargetPathTrait's never gets written. The value is recorded server-side
        // from Request::getUri() - same-origin by construction. The client-writable
        // auth0_redirect_target cookie is deliberately NOT honored here (open
        // redirect). Falls away with the Auth0 fork in Phase 6.
        $auth0CallbackRedirect = $session->get('auth0:callback_redirect');

        if (is_string($auth0CallbackRedirect) && $auth0CallbackRedirect !== '') {
            $session->remove('auth0:callback_redirect');

            return new RedirectResponse($auth0CallbackRedirect);
        }

        return new RedirectResponse($this->urlGenerator->generate('my_profile'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('login');
    }

    /**
     * Brute-force protection scoped to this authenticator on purpose - the
     * firewall-level login_throttling listener consumes budget on every
     * LoginFailureEvent, and during the migration window the Auth0 authenticator
     * fails on every anonymous request, which would exhaust the per-IP budget
     * through plain browsing (see config/packages/rate_limiter.php).
     */
    private function throttle(string $email, null|string $clientIp): void
    {
        $emailIpLimit = $this->loginEmailIpLimiter->create($this->emailIpKey($email, $clientIp))->consume();
        $ipLimit = $this->loginIpLimiter->create($clientIp ?? 'unknown')->consume();

        foreach ([$emailIpLimit, $ipLimit] as $limit) {
            if (!$limit->isAccepted()) {
                $retryAfter = $limit->getRetryAfter();

                throw new TooManyLoginAttemptsAuthenticationException(
                    (int) ceil(($retryAfter->getTimestamp() - time()) / 60),
                );
            }
        }
    }

    private function emailIpKey(string $email, null|string $clientIp): string
    {
        return UserAccount::canonicalizeEmail($email) . '|' . ($clientIp ?? 'unknown');
    }

    private function shouldVerifyViaTrickle(null|UserAccount $userAccount): bool
    {
        return $this->auth0TrickleLoginEnabled
            && $userAccount !== null
            && $userAccount->password === null
            && $userAccount->legacyAuth0;
    }

    /**
     * @return callable(mixed, UserInterface): bool
     */
    private function verifyViaTrickle(null|string $clientIp): callable
    {
        return function (mixed $plainPassword, UserInterface $userAccount) use ($clientIp): bool {
            assert(is_string($plainPassword));
            assert($userAccount instanceof UserAccount);

            $result = $this->tricklePasswordVerifier->verify($userAccount->email, $plainPassword, $clientIp);

            return match ($result) {
                TrickleVerificationResult::Verified => $this->adoptVerifiedPassword($userAccount, $plainPassword),
                TrickleVerificationResult::InvalidCredentials => false,
                TrickleVerificationResult::PasswordLeaked => throw new CustomUserMessageAuthenticationException(self::ERROR_PASSWORD_LEAKED),
                TrickleVerificationResult::Unavailable => throw new CustomUserMessageAuthenticationException(self::ERROR_TEMPORARILY_UNAVAILABLE),
            };
        };
    }

    private function adoptVerifiedPassword(UserAccount $userAccount, string $plainPassword): bool
    {
        // Hash the verified password locally so every subsequent login takes the
        // PasswordCredentials branch - Auth0 is consulted at most once per user
        $this->userAccountProvider->upgradePassword(
            $userAccount,
            $this->passwordHasher->hashPassword($userAccount, $plainPassword),
        );

        // Phase 5 exit-metric counter: trickle must trend to ~0 before decommission
        $this->logger->info('Trickle login adopted the password locally.', [
            'user_id' => $userAccount->getUserIdentifier(),
            'trickle_used' => true,
        ]);

        return true;
    }
}
