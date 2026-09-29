<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Entity\LoginLinkRequest;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\VerifySignInCode;
use SpeedPuzzling\Web\Services\SignInCodeHasher;
use SpeedPuzzling\Web\Services\SignInCodePending;
use SpeedPuzzling\Web\Value\SignInCodeCheck;
use SpeedPuzzling\Web\Value\SignInCodeOutcome;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Signs in with the 6-digit code from the sign-in e-mail (auth UX redesign
 * phase 2), in the browser that asked for it (SignInCodePending) - the in-app
 * browser case, where tapping the link would sign in the phone's own browser.
 *
 * Claims exactly one request: POST /login-link/code. Symfony's RememberMeListener
 * clears the remember-me cookie on every LoginFailureEvent, so an authenticator
 * on `main` must never fail on anything else.
 *
 * Order of checks: something pending in this browser -> CSRF -> six digits ->
 * per-address and per-IP limiters -> VerifySignInCodeHandler (row lock, attempt
 * cap 5, consumption). A success is an ordinary login: session migrated, the
 * always-on remember-me cookie, LoginSuccessEvent (audit: sign_in_code_used).
 * A failure hands the request on to SignInCodeController, which re-renders the
 * screen with 422 (Turbo Drive drops a 200 answer to a form POST).
 */
final class SignInCodeAuthenticator extends AbstractAuthenticator
{
    public const string ROUTE = 'sign_in_code';
    public const string CSRF_TOKEN_ID = 'sign_in_code';

    /** Request attribute carrying the failure to SignInCodeController */
    public const string FAILURE_ATTRIBUTE = '_sign_in_code_failure';

    public function __construct(
        private readonly SignInCodePending $signInCodePending,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly MessageBusInterface $messageBus,
        private readonly UserAccountProvider $userAccountProvider,
        private readonly LoginLinkSuccessHandler $loginLinkSuccessHandler,
        private readonly RateLimiterFactoryInterface $signInCodeEmailLimiter,
        private readonly RateLimiterFactoryInterface $signInCodeIpLimiter,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && $request->attributes->get('_route') === self::ROUTE;
    }

    public function authenticate(Request $request): Passport
    {
        $pending = $this->signInCodePending->get($request);

        if ($pending === null) {
            throw new SignInCodeRejected(SignInCodeOutcome::NoPendingRequest, null);
        }

        $email = $pending['email'];

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }

        $code = SignInCodeHasher::normalize($request->request->getString('code'));

        if ($code === null) {
            // Not a guess - nothing counted
            throw new SignInCodeRejected(SignInCodeOutcome::Malformed, $email);
        }

        if (!$this->consumeRateLimit($email, $request->getClientIp())) {
            throw new SignInCodeRejected(SignInCodeOutcome::Throttled, $email);
        }

        $envelope = $this->messageBus->dispatch(new VerifySignInCode($pending['request_id'], $code));
        $check = $envelope->last(HandledStamp::class)?->getResult();
        assert($check instanceof SignInCodeCheck);

        if ($check->outcome === SignInCodeOutcome::Unknown) {
            // No account behind the address (or the row is gone): look exactly like
            // a wrong code, countdown included (D8)
            $attemptsLeft = LoginLinkRequest::MAX_CODE_ATTEMPTS - $this->signInCodePending->recordMiss($request);
            $check = SignInCodeCheck::wrong(max(0, $attemptsLeft));
        }

        if ($check->outcome !== SignInCodeOutcome::Accepted || $check->userId === null) {
            if ($check->outcome !== SignInCodeOutcome::Wrong) {
                // Nothing more this code can do - the screen says what to do instead
                $this->signInCodePending->clear($request);
            }

            throw new SignInCodeRejected($check->outcome, $email, $check->attemptsLeft);
        }

        $this->signInCodePending->clear($request);

        if ($check->returnPath !== null) {
            $request->attributes->set(SingleUseLoginLinkHandler::RETURN_PATH_ATTRIBUTE, $check->returnPath);
        }

        $userId = $check->userId;

        return new SelfValidatingPassport(
            new UserBadge($userId, fn (): UserAccount => $this->userAccountProvider->loadUserByIdentifier($userId)),
            [new RememberMeBadge()],
        );
    }

    /**
     * Same landing as the link: the legacy set-password prompt, else the booked
     * ?return=, else the profile. 303 - the answer to a (Turbo) form POST.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $response = $this->loginLinkSuccessHandler->onAuthenticationSuccess($request, $token);
        $response->setStatusCode(Response::HTTP_SEE_OTHER);

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): null|Response
    {
        $request->attributes->set(self::FAILURE_ATTRIBUTE, $exception);

        // Continue to SignInCodeController, which renders the answer
        return null;
    }

    /**
     * On top of the per-request cap of 5: per address (several requests in a row)
     * and per IP (one place guessing for many addresses).
     */
    private function consumeRateLimit(string $email, null|string $clientIp): bool
    {
        $perEmail = $this->signInCodeEmailLimiter
            ->create(UserAccount::canonicalizeEmail($email))
            ->consume();

        $perIp = $this->signInCodeIpLimiter
            ->create($clientIp ?? 'unknown')
            ->consume();

        return $perEmail->isAccepted() && $perIp->isAccepted();
    }
}
