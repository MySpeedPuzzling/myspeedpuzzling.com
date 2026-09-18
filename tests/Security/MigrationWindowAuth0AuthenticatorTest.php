<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use Auth0\Symfony\Models\User as Auth0User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Security\MigrationWindowAuth0Authenticator;
use SpeedPuzzling\Web\Tests\TestDouble\RedirectingAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * @see MigrationWindowAuth0Authenticator for the bug this exists to prevent;
 *      AdminAreaAccessTest covers the same thing end to end
 */
final class MigrationWindowAuth0AuthenticatorTest extends TestCase
{
    public function testFailureOnAnAlreadyAuthenticatedRequestProducesNoResponse(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(
            new PostAuthenticationToken(new InMemoryUser('msp|someone', null), 'main', ['ROLE_USER']),
        );

        $authenticator = new MigrationWindowAuth0Authenticator(new RedirectingAuthenticator(), $tokenStorage);

        // A response here is what short-circuits the request and takes the page
        // away from a visitor who is signed in and entitled to it
        self::assertNull($authenticator->onAuthenticationFailure(
            Request::create('/admin/moderation'),
            new CustomUserMessageAuthenticationException('No Auth0 session was found.'),
        ));
    }

    public function testFailureWithoutATokenStillDelegatesToTheBundle(): void
    {
        $authenticator = new MigrationWindowAuth0Authenticator(new RedirectingAuthenticator(), new TokenStorage());

        $response = $authenticator->onAuthenticationFailure(
            Request::create('/admin/moderation'),
            new CustomUserMessageAuthenticationException('No Auth0 session was found.'),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());
    }

    public function testNativeSessionIsNotTakenOverByLeftoverAuth0Credentials(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new PostAuthenticationToken(
            new UserAccount(Uuid::uuid7(), 'auth0|someone', 'someone@example.com', new DateTimeImmutable()),
            'main',
            ['ROLE_USER'],
        ));

        $authenticator = new MigrationWindowAuth0Authenticator(new RedirectingAuthenticator(), $tokenStorage);

        // The inner authenticator would succeed (it stands for a session that still
        // carries Auth0 SDK credentials) - it must not get the chance to
        $this->expectException(AuthenticationException::class);

        $authenticator->authenticate(Request::create('/set-password'));
    }

    public function testLegacyAuth0SessionIsStillRefreshedFromItsCredentials(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new PostAuthenticationToken(
            new Auth0User(['user_id' => 'auth0|someone', 'sub' => 'auth0|someone']),
            'main',
            ['ROLE_USER'],
        ));

        $inner = new RedirectingAuthenticator();
        $authenticator = new MigrationWindowAuth0Authenticator($inner, $tokenStorage);

        self::assertSame($inner->passport, $authenticator->authenticate(Request::create('/en/puzzle')));
    }

    public function testEverythingElseIsPassedStraightThrough(): void
    {
        $inner = new RedirectingAuthenticator();
        $authenticator = new MigrationWindowAuth0Authenticator($inner, new TokenStorage());
        $request = Request::create('/en/puzzle');

        self::assertTrue($authenticator->supports($request));
        self::assertSame($inner->passport, $authenticator->authenticate($request));
        self::assertSame($inner->token, $authenticator->createToken($inner->passport, 'main'));
        self::assertNull($authenticator->onAuthenticationSuccess($request, $inner->token, 'main'));
    }
}
