<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Phase 6 of the Auth0 migration removed the Auth0 bundle, but sessions created
 * through it live on in the sessions table for up to 30 days: a security token
 * whose user is an Auth0\Symfony\Models\Stateful\User - a class that no longer
 * exists - plus the SDK's own credentials under `auth0_session`.
 *
 * Such a visitor must simply be signed out: no 500 from unserializing a missing
 * class, no redirect loop, and a normal sign-in from the very same browser.
 */
final class LeftoverAuth0SessionTest extends WebTestCase
{
    private const string AUTH0_USER_CLASS = 'Auth0\Symfony\Models\Stateful\User';

    public function testLegacySessionIsTreatedAsSignedOutAndForgetsTheDeadToken(): void
    {
        $browser = self::createClient();
        $this->plantLegacyAuth0Session($browser, withSecurityToken: true);

        $browser->request('GET', '/en/puzzle');

        self::assertResponseIsSuccessful();
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());

        // The planted session really was the one in play (the inert SDK key is still
        // there) - and ContextListener dropped the token it could not read, so the
        // next request of this browser does not stumble over the same dead class
        $session = $browser->getRequest()->getSession();
        self::assertTrue($session->has('auth0_session'));
        self::assertFalse($session->has('_security_main'));
    }

    public function testSdkCredentialsWithoutATokenAreInert(): void
    {
        $browser = self::createClient();
        $this->plantLegacyAuth0Session($browser, withSecurityToken: false);

        $browser->request('GET', '/en/puzzle');

        self::assertResponseIsSuccessful();
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    public function testProtectedPageSendsTheLegacyVisitorToTheLoginFormOnce(): void
    {
        $browser = self::createClient();
        $this->plantLegacyAuth0Session($browser, withSecurityToken: true);

        $browser->request('GET', '/en/edit-profile');
        self::assertResponseRedirects('/login?return=/en/edit-profile');

        // ...and the form renders instead of bouncing them anywhere else
        $crawler = $browser->request('GET', '/login?return=/en/edit-profile');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form#login-form'));
    }

    public function testSigningInFromTheLegacyBrowserWorks(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser);
        $this->plantLegacyAuth0Session($browser, withSecurityToken: true);

        $browser->request('POST', '/login', [
            'email' => $email,
            'password' => 'leftover-auth0-session-password',
            '_csrf_token' => 'csrf-token',
        ], [], [
            // Stateless CSRF validates same-origin via Origin; BrowserKit sends none
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        self::assertResponseRedirects('/en/my-profile');

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertInstanceOf(UserAccount::class, $token->getUser());
    }

    /**
     * What an Auth0-era session holds: the SDK's credentials under the bundle's
     * session store namespace and, once the Auth0 authenticator had run, the
     * firewall's serialized token around an Auth0 user object. The token is built
     * with a stand-in user and its class name swapped afterwards, because the
     * real class is gone from vendor/ - exactly the situation in production.
     */
    private function plantLegacyAuth0Session(KernelBrowser $browser, bool $withSecurityToken): void
    {
        $session = $browser->getContainer()->get('session.factory')->createSession();

        $session->set('auth0_session', [
            'user' => [
                'sub' => 'auth0|leftover',
                'user_id' => 'auth0|leftover',
                'email' => 'leftover@example.com',
                'email_verified' => true,
            ],
            'accessToken' => 'legacy-auth0-access-token',
            'accessTokenExpiration' => time() + 3600,
        ]);

        if ($withSecurityToken) {
            $session->set('_security_main', $this->serializedTokenAroundAnAuth0User());
        }

        $session->save();

        // The host BrowserKit files the server's own session cookie under - a
        // domain-less cookie would shadow the one a login migrates the session to
        $browser->getCookieJar()->set(new Cookie($session->getName(), $session->getId(), domain: 'localhost'));
    }

    private function serializedTokenAroundAnAuth0User(): string
    {
        $standIn = new InMemoryUser('auth0|leftover', null, ['ROLE_USER']);
        $serialized = serialize(new PostAuthenticationToken($standIn, 'main', ['ROLE_USER']));

        $standInClass = sprintf('O:%d:"%s"', strlen(InMemoryUser::class), InMemoryUser::class);
        self::assertStringContainsString($standInClass, $serialized);
        self::assertFalse(class_exists(self::AUTH0_USER_CLASS), 'The Auth0 bundle is supposed to be gone');

        return str_replace(
            $standInClass,
            sprintf('O:%d:"%s"', strlen(self::AUTH0_USER_CLASS), self::AUTH0_USER_CLASS),
            $serialized,
        );
    }

    private function seedAccount(KernelBrowser $browser): string
    {
        // Randomized: the login rate limiter's cache survives across tests and runs
        $email = sprintf('leftover.auth0+%s@example.com', bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), 'auth0|' . bin2hex(random_bytes(4)), $email, new DateTimeImmutable());
        $userAccount->applyAuth0Import(
            $email,
            password_hash('leftover-auth0-session-password', PASSWORD_BCRYPT, ['cost' => 4]),
            true,
            new DateTimeImmutable(),
        );

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $email;
    }
}
