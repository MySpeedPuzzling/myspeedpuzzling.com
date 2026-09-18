<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The password authenticator (issue #147): email + password against the local
 * hash, with the bcrypt->argon2id migrate_from rehash for accounts imported from
 * Auth0 that still carry their original bcrypt hash.
 */
final class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private readonly UserAccountRepository $userAccountRepository,
        private readonly UserAccountProvider $userAccountProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RateLimiterFactoryInterface $loginEmailIpLimiter,
        private readonly RateLimiterFactoryInterface $loginIpLimiter,
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

        return new Passport($userBadge, new PasswordCredentials($password), [
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

        // Where to go next travels in the form, not the session: the login page
        // received it as ?return= from LoginEntryPoint and echoes it back in a
        // hidden field. Validated because the POST body is client-controlled -
        // an unchecked value here is a post-login open redirect, the most
        // valuable kind (docs/features/return-url.md).
        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        if ($returnUrl !== null) {
            return new RedirectResponse($returnUrl->path);
        }

        return new RedirectResponse($this->urlGenerator->generate('my_profile'));
    }

    /**
     * Must stay the bare login path: AbstractLoginFormAuthenticator::supports()
     * compares this against the request path to decide whether to intercept the
     * POST at all, so appending a query string here silently stops the
     * authenticator from ever running. The ?return= is added in
     * onAuthenticationFailure() instead.
     */
    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('login');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        // Send the destination back to the login page too, or a typo'd password
        // would silently cost the user the page they were headed for
        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        return new RedirectResponse($this->urlGenerator->generate(
            'login',
            $returnUrl === null ? [] : ['return' => $returnUrl->path],
        ));
    }

    /**
     * Brute-force protection lives here rather than in the firewall-level
     * login_throttling listener (config/packages/rate_limiter.php): a per
     * email+IP limiter against guessing one account, a per-IP one against
     * spraying many.
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
}
