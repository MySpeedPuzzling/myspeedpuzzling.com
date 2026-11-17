<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\LiveEntryDeviceCookie;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The device cookie `msp_live_player` (LiveEntryDeviceCookie, competition toolkit C3 - C §1.3, R §2.7): set by the live
 * entry page for its organisers and referees only, readable by the page's JS, and taken away on sign-out and when
 * another player signs in on the device - the same player signing in again keeps it.
 */
final class LiveEntryDeviceCookieTest extends WebTestCase
{
    private const string LIVE = '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string REFEREE = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string PASSWORD = 'live-entry-device-cookie-password';

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleOfTheLivePage(): iterable
    {
        yield 'organiser' => [self::ORGANISER];
        yield 'referee' => [self::REFEREE];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('peopleOfTheLivePage')]
    public function testTheLivePageSetsTheCookieForItsOrganisersAndReferees(string $playerId): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'player_id' => self::REFEREE,
            'added_by_id' => self::ORGANISER,
            'added_at' => '2026-10-01 10:00:00',
        ]);
        TestingLogin::asPlayer($browser, $playerId);

        $browser->request('GET', self::LIVE);

        self::assertResponseIsSuccessful();
        $cookie = self::responseCookie($browser);
        self::assertNotNull($cookie);
        self::assertSame($playerId, $cookie->getValue());
        self::assertFalse($cookie->isCleared());
        self::assertFalse($cookie->isHttpOnly(), 'the offline copy\'s JS reads it');
        self::assertSame('/', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertGreaterThan(29 * 86400, $cookie->getMaxAge(), 'as long as the always-on sign-in');
    }

    public function testNobodyElseEverGetsIt(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::LIVE);
        self::assertResponseRedirects();
        self::assertNull(self::responseCookie($browser), 'a guest');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::LIVE);
        self::assertResponseStatusCodeSame(403);
        self::assertNull(self::responseCookie($browser), 'a player without rights');

        $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertNull(self::responseCookie($browser), 'any other page');
    }

    public function testSigningOutClearsIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ORGANISER);
        $browser->request('GET', self::LIVE);
        self::assertResponseIsSuccessful();

        $browser->request('GET', '/logout');

        self::assertResponseRedirects('/');
        $cleared = self::responseCookie($browser);
        self::assertNotNull($cleared);
        self::assertTrue($cleared->isCleared());
        self::assertSame('/', $cleared->getPath());
        self::assertFalse($cleared->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cleared->getSameSite());
    }

    public function testAnotherPlayerSigningInClearsIt(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|livecookie' . bin2hex(random_bytes(4)));
        $browser->getCookieJar()->set(new BrowserCookie(LiveEntryDeviceCookie::NAME, self::ORGANISER));

        $this->submitLogin($browser, $email);

        self::assertSignedIn($browser);
        $cleared = self::responseCookie($browser);
        self::assertNotNull($cleared, 'the signed-in player is not the one the cookie names');
        self::assertTrue($cleared->isCleared());
    }

    public function testTheSamePlayerSigningInAgainKeepsIt(): void
    {
        $browser = self::createClient();
        $player = $browser->getContainer()->get(PlayerRepository::class)->get(self::ORGANISER);
        self::assertNotNull($player->userId);
        $email = $this->seedAccount($browser, $player->userId);
        $browser->getCookieJar()->set(new BrowserCookie(LiveEntryDeviceCookie::NAME, self::ORGANISER));

        $this->submitLogin($browser, $email);

        self::assertSignedIn($browser);
        self::assertNull(self::responseCookie($browser));
    }

    public function testSigningInWithoutTheCookieLeavesItAlone(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|livecookie' . bin2hex(random_bytes(4)));

        $this->submitLogin($browser, $email);

        self::assertSignedIn($browser);
        self::assertNull(self::responseCookie($browser));
    }

    private static function assertSignedIn(KernelBrowser $browser): void
    {
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $browser->getResponse()->headers->get('Location'), 'the sign-in succeeded');
    }

    private static function responseCookie(KernelBrowser $browser): null|Cookie
    {
        foreach ($browser->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === LiveEntryDeviceCookie::NAME) {
                return $cookie;
            }
        }

        return null;
    }

    private function submitLogin(KernelBrowser $browser, string $email): void
    {
        $browser->request('POST', '/login', [
            'email' => $email,
            'password' => self::PASSWORD,
            '_csrf_token' => 'csrf-token',
        ], [], [
            // Stateless CSRF validates same-origin via Origin; BrowserKit sends none
            'HTTP_ORIGIN' => 'http://localhost',
        ]);
    }

    /**
     * A password account for the user id (a fixture player's, or a new one without a player) - random e-mail per run:
     * the login rate limiter's cache outlives the test's transaction
     */
    private function seedAccount(KernelBrowser $browser, string $userId): string
    {
        $email = sprintf('live.cookie+%s@example.com', bin2hex(random_bytes(4)));
        $container = $browser->getContainer();
        $userAccount = $container->get(UserAccountRepository::class)->findByUserId($userId)
            ?? new UserAccount(Uuid::uuid7(), $userId, $email, TestClock::now());
        $userAccount->changePassword(password_hash(self::PASSWORD, PASSWORD_ARGON2ID));

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $userAccount->email;
    }
}
