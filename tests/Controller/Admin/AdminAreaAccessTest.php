<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\PredictableTrickleVerifier;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The happy path of the whole /admin area, which nothing covered before: every
 * admin controller test asserted only that an anonymous visitor is turned away,
 * so the area could be - and was - completely unreachable for actual admins
 * without a single test failing.
 *
 * What it caught: the Auth0 authenticator claims every request carrying a
 * session cookie and fails on every native (post-Stage-B) session, and on a page
 * whose access_control pattern was not PUBLIC_ACCESS its failure response - a
 * redirect to /login - short-circuited the request. /login then forwarded the
 * already-signed-in admin to my_profile. See MigrationWindowAuth0Authenticator.
 *
 * Each of the three ways a visitor can arrive signed in is exercised, because
 * they fail differently: a native session token, a legacy Auth0 session token,
 * and a RememberMeToken restored from the always-on 30-day cookie (which is not
 * "full fledged", so any IS_AUTHENTICATED_FULLY rule rejects it).
 */
final class AdminAreaAccessTest extends WebTestCase
{
    private const string PASSWORD = 'admin-area-access-test-password';

    /**
     * Every admin page reachable without knowing an entity id.
     *
     * @return iterable<string, array{string}>
     */
    public static function adminPages(): iterable
    {
        yield 'moderation' => ['/admin/moderation'];
        yield 'affiliates' => ['/admin/affiliates'];
        yield 'competition approvals' => ['/admin/competition-approvals'];
        yield 'email audit' => ['/admin/email-audit'];
        yield 'oauth2 requests' => ['/admin/oauth2-requests'];
        yield 'puzzle change requests' => ['/admin/puzzle-change-requests'];
        yield 'puzzle merge requests' => ['/admin/puzzle-merge-requests'];
        yield 'vouchers' => ['/admin/vouchers'];
    }

    #[DataProvider('adminPages')]
    public function testAdminReachesEveryAdminPage(string $path): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    /**
     * The legacy half of the migration window: a session holding an Auth0 bundle
     * user rather than a native UserAccount must reach the area just the same.
     */
    public function testAdminOnALegacyAuth0SessionReachesTheAdminArea(): void
    {
        $browser = self::createClient();
        TestingLogin::asAuth0Player($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/admin/moderation');

        self::assertResponseIsSuccessful();
    }

    /**
     * Remember-me is always on and slides for 30 days, so an admin whose session
     * has gone (expired, or the sessions table pruned) arrives holding only the
     * cookie. The resulting RememberMeToken fails IS_AUTHENTICATED_FULLY, which
     * is why ^/admin asks for ADMIN_ACCESS instead - the voter reads
     * player.isAdmin and does not care how the visitor was authenticated.
     */
    public function testAdminSignedBackInFromTheRememberMeCookieReachesTheAdminArea(): void
    {
        PredictableTrickleVerifier::reset();

        $browser = self::createClient();
        $email = $this->givePasswordToAdmin($browser);

        $browser->request('POST', '/login', [
            'email' => $email,
            'password' => self::PASSWORD,
            '_csrf_token' => 'csrf-token',
        ], [], [
            // Stateless CSRF validates same-origin via Origin; BrowserKit sends none
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        $jar = $browser->getCookieJar();
        $rememberMe = $jar->get('REMEMBERME');
        self::assertNotNull($rememberMe, 'Signing in must mint a remember-me cookie');

        // Everything but the cookie is gone - the only situation in which it is consumed
        $jar->clear();
        $jar->set($rememberMe);

        $browser->request('GET', '/admin/moderation');

        self::assertResponseIsSuccessful();
    }

    /**
     * The area is closed to everyone else, and closed with a 403 rather than a
     * bounce to /login: they are signed in, so there is nothing to sign in to.
     */
    #[DataProvider('adminPages')]
    public function testSignedInNonAdminIsForbidden(string $path): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $path);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[DataProvider('adminPages')]
    public function testAnonymousVisitorIsSentToLoginWithTheDestination(string $path): void
    {
        $browser = self::createClient();

        $browser->request('GET', $path);

        self::assertResponseRedirects('/login?return=' . $path);
    }

    /**
     * The admin fixture is an Auth0-era identity with no password. Give it one so
     * it can go through the real login form, which is the only thing that mints a
     * remember-me cookie. The address is randomized per run: the login rate
     * limiter's cache is not rolled back between tests.
     */
    private function givePasswordToAdmin(KernelBrowser $browser): string
    {
        $container = $browser->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $player = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_ADMIN);
        assert($player->userId !== null);

        $email = sprintf('admin.area+%s@example.com', bin2hex(random_bytes(4)));
        $player->email = $email;

        $account = $container->get(UserAccountRepository::class)->findByUserId($player->userId);

        if ($account === null) {
            $account = new UserAccount(Uuid::uuid7(), $player->userId, $email, new DateTimeImmutable());
            $entityManager->persist($account);
        } else {
            $account->changeEmail($email);
        }

        $account->changePassword(password_hash(self::PASSWORD, PASSWORD_ARGON2ID));
        $entityManager->flush();

        return $email;
    }
}
