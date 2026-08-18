<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Stands in for Auth0's authenticator in MigrationWindowAuth0AuthenticatorTest:
 * it answers every request and redirects to /login when it fails, which is the
 * behaviour the wrapper exists to contain. The real class is final and so
 * cannot be doubled.
 */
final class RedirectingAuthenticator implements AuthenticatorInterface
{
    public Passport $passport;

    public TokenInterface $token;

    public function __construct()
    {
        $user = new InMemoryUser('msp|someone', null);

        $this->passport = new SelfValidatingPassport(
            new UserBadge('msp|someone', static fn (): InMemoryUser => new InMemoryUser('msp|someone', null)),
        );
        $this->token = new PostAuthenticationToken($user, 'main', ['ROLE_USER']);
    }

    public function supports(Request $request): null|bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        return $this->passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return $this->token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): null|Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new RedirectResponse('/login');
    }
}
