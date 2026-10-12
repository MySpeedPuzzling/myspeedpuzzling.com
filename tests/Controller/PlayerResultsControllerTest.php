<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A player's results (docs/features/navigation README §4.7, PROF-00): the profile's results list under the player
 * header, in every locale. A player hidden from the viewer is a 404 (GetPlayerProfile::byId), a private profile the
 * viewer may not see goes to the profile (implementation plan decision 69).
 */
final class PlayerResultsControllerTest extends WebTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [null];
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'the player' => [self::PLAYER];
    }

    #[DataProvider('provideViewers')]
    public function testThePublicPlayersResults(null|string $viewerId): void
    {
        $browser = $this->browser($viewerId);

        $profile = $browser->request('GET', '/en/player-profile/' . self::PLAYER);
        self::assertResponseIsSuccessful();

        $crawler = $browser->request('GET', '/en/player-results/' . self::PLAYER);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        // The player header with the name as the page's H1, and today's list of the profile - row for row
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $this->resultsList($crawler));
        self::assertGreaterThan(0, $this->rows($crawler));
        self::assertSame($this->rows($profile), $this->rows($crawler));

        if ($viewerId === null) {
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalePaths(): iterable
    {
        yield 'cs' => ['/vysledky-hrace/'];
        yield 'de' => ['/de/spieler-ergebnisse/'];
        yield 'es' => ['/es/resultados-jugador/'];
        yield 'fr' => ['/fr/resultats-joueur/'];
        yield 'ja' => ['/ja/プレイヤー結果/'];
    }

    #[DataProvider('provideLocalePaths')]
    public function testEveryLocaleHasThePage(string $path): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $path . self::PLAYER);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->resultsList($crawler));
    }

    public function testAPlayerTheViewerBlockedDoesNotExistForThem(): void
    {
        $browser = self::createClient();
        $url = '/en/player-results/' . PlayerFixture::PLAYER_WITH_STRIPE;

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnknownPlayers(): iterable
    {
        yield 'unknown uuid' => ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'];
        yield 'not a uuid' => ['not-a-player'];
    }

    #[DataProvider('provideUnknownPlayers')]
    public function testAnUnknownPlayerIsNotFound(string $playerId): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-results/' . $playerId);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAPrivateProfileHiddenFromTheViewerGoesToTheProfile(): void
    {
        $browser = self::createClient();
        $url = '/en/player-results/' . PlayerFixture::PLAYER_PRIVATE;
        $profile = '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE;

        // A guest and a signed-in stranger
        $browser->request('GET', $url);
        self::assertResponseRedirects($profile, 302);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $url);
        self::assertResponseRedirects($profile, 302);

        // The owner, and the friend her allow list names (PrivateProfileViewerFixture), see the list
        // (the head follows her own setting, not the friend's view of her - as on the profile)
        foreach ([PlayerFixture::PLAYER_PRIVATE => 'noindex, follow', PlayerFixture::PLAYER_WITH_FAVORITES => 'noindex, nofollow'] as $viewerId => $robots) {
            TestingLogin::asPlayer($browser, $viewerId);
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $this->resultsList($crawler));
            self::assertSame($robots, $crawler->filter('meta[name="robots"]')->attr('content'));
        }
    }

    private function browser(null|string $viewerId): KernelBrowser
    {
        $browser = self::createClient();

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        return $browser;
    }

    private function resultsList(Crawler $crawler): Crawler
    {
        return $crawler->filter('main [data-live-name-value="PlayerSolvedPuzzles"]');
    }

    private function rows(Crawler $crawler): int
    {
        return $this->resultsList($crawler)->filter('.ps-table tbody tr')->count();
    }
}
