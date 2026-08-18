<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Stands in for any non-Auth0 authenticator (the native login form, a social
 * provider, the magic link) in MigrationWindowRememberMeListenerTest. Those
 * classes are all final and so cannot be doubled.
 */
final class FakeInteractiveAuthenticator implements AuthenticatorInterface
{
    public function supports(Request $request): null|bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        throw new LogicException('Not needed for these tests.');
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        throw new LogicException('Not needed for these tests.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): null|Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): null|Response
    {
        return null;
    }
}
