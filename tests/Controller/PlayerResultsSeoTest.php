<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\PlayerResultsSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PlayerOverview;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What search engines read on a player's Results (docs/features/navigation README §4.7 SEO, PROF-07): the list's own
 * URL is indexable (canonical + hreflang) once the player has more results than the Overview shows - before that it
 * has its canonical, no hreflang (QA-04 R22: alternates only where the page is indexable); `?page=N` is
 * followed, never indexed, with its own canonical; every filtered or sorted variant points at the list; a private
 * profile is neither indexed nor followed. The head names the player the way the profile does - masked by the player's
 * own setting - and the profile's own title and description stay as they were.
 */
final class PlayerResultsSeoTest extends WebTestCase
{
    use PlayerResultsSeeding;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    private const string BASE = 'http://localhost/en/player-results/';

    private null|KernelBrowser $browser = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheListsOwnUrl(): iterable
    {
        yield 'bare' => [''];
        yield '?page=1' => ['?page=1'];
        yield 'where a link came from is no variant' => ['?return=%2Fen%2Fhub&return_title=Home'];
    }

    #[DataProvider('provideTheListsOwnUrl')]
    public function testTheListIsIndexedWhenThePlayerHasMoreResultsThanTheOverviewShows(string $query): void
    {
        $crawler = $this->page(self::PLAYER, $query);

        self::assertSame('index, follow', $this->robots($crawler));
        self::assertSame(self::BASE . self::PLAYER, $this->canonical($crawler));
        self::assertSame([
            'cs' => 'http://localhost/vysledky-hrace/' . self::PLAYER,
            'en' => self::BASE . self::PLAYER,
            'es' => 'http://localhost/es/resultados-jugador/' . self::PLAYER,
            'ja' => 'http://localhost/ja/%E3%83%97%E3%83%AC%E3%82%A4%E3%83%A4%E3%83%BC%E7%B5%90%E6%9E%9C/' . self::PLAYER,
            'fr' => 'http://localhost/fr/resultats-joueur/' . self::PLAYER,
            'de' => 'http://localhost/de/spieler-ergebnisse/' . self::PLAYER,
            'x-default' => self::BASE . self::PLAYER,
        ], $this->hreflang($crawler));
        self::assertTrue($this->browser()->getResponse()->headers->hasCacheControlDirective('public'), 'The same for every guest');
    }

    /**
     * @return iterable<string, array{int, string, int}>
     */
    public static function provideTheThreshold(): iterable
    {
        // Not indexable: the canonical, no alternates (decision 2026-10-15, QA-04 R22: README §4.7 read literally)
        yield 'as many results as the Overview shows' => [PlayerOverview::LATEST_RESULTS, 'noindex, follow', 0];
        // Indexable: the canonical and the 7 alternates (6 languages + x-default)
        yield 'one more' => [PlayerOverview::LATEST_RESULTS + 1, 'index, follow', 7];
    }

    #[DataProvider('provideTheThreshold')]
    public function testAPlayerWithNoMoreResultsThanTheOverviewShowsStaysOutOfTheIndex(int $results, string $robots, int $alternates): void
    {
        $this->browser();
        $playerId = $this->seedPlayerWithSoloResults($results);

        foreach (['', '?page=1'] as $query) {
            $crawler = $this->page($playerId, $query);

            self::assertSame($robots, $this->robots($crawler), $query);
            self::assertSame(self::BASE . $playerId, $this->canonical($crawler), $query);
            self::assertCount($alternates, $this->hreflang($crawler), $query);
        }
    }

    public function testAPageIsFollowedNeverIndexedWithItsOwnCanonical(): void
    {
        $this->browser();
        $this->addSoloResults(self::PLAYER, 60);

        $crawler = $this->page(self::PLAYER, '?page=2');

        self::assertSame('noindex, follow', $this->robots($crawler));
        self::assertSame(self::BASE . self::PLAYER . '?page=2', $this->canonical($crawler));
        self::assertSame([], $this->hreflang($crawler));

        // Beyond the end: the page it really shows
        self::assertSame(self::BASE . self::PLAYER . '?page=2', $this->canonical($this->page(self::PLAYER, '?page=9')));
        // A short list has one page: the list
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, '?page=2');
        self::assertSame('noindex, follow', $this->robots($crawler));
        self::assertSame(self::BASE . PlayerFixture::PLAYER_WITH_STRIPE, $this->canonical($crawler));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideVariants(): iterable
    {
        yield 'a sort' => ['?sort=fastest'];
        yield 'a page of a sort' => ['?page=2&sort=fastest'];
        yield 'the default sort named' => ['?sort=newest'];
        yield 'every result' => ['?view=every'];
        yield 'a tab' => ['?category=duo'];
        yield 'a search' => ['?q=puzzle'];
        yield 'a filter' => ['?pieces=500'];
        yield 'anything else' => ['?utm_source=newsletter'];
    }

    #[DataProvider('provideVariants')]
    public function testEveryVariantIsFollowedAndPointsAtTheList(string $query): void
    {
        $this->browser();
        $this->addSoloResults(self::PLAYER, 60);

        $crawler = $this->page(self::PLAYER, $query);

        self::assertSame('noindex, follow', $this->robots($crawler));
        self::assertSame(self::BASE . self::PLAYER, $this->canonical($crawler));
        self::assertSame([], $this->hreflang($crawler));
    }

    public function testThePlayersOwnPrivacySettingDecidesTheHead(): void
    {
        $browser = $this->browser();
        $code = self::getContainer()->get(Connection::class)->fetchOne('SELECT code FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]);
        self::assertIsString($code);
        $url = '/en/player-results/' . PlayerFixture::PLAYER_PRIVATE;

        // The friend her allow list names sees the list - its head never names her
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $this->robots($crawler));
        self::assertSame('Hidden Puzzler #' . strtoupper($code) . ' – Puzzle Results – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertStringContainsString('Hidden Puzzler #' . strtoupper($code), (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame([], $this->hreflang($crawler));

        // She sees her own name - and her list stays out of the index all the same
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', $url);
        self::assertSame('noindex, nofollow', $this->robots($crawler));
        self::assertStringNotContainsString('Hidden Puzzler', $crawler->filter('title')->text());
    }

    public function testTheHeadNamesThePlayerLikeTheProfile(): void
    {
        $crawler = $this->page(self::PLAYER, '');

        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME . ' – Puzzle Results – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertSame(
            'Every jigsaw puzzle result of ' . PlayerFixture::PLAYER_REGULAR_NAME . ': solo, pair and team times, searchable and sortable on MySpeedPuzzling.',
            $crawler->filter('meta[name="description"]')->attr('content'),
        );
        self::assertCount(0, $crawler->filter('script[type="application/ld+json"]:contains("ProfilePage")'), 'No JSON-LD of its own');

        // The profile's own head is unchanged
        $profile = $this->browser()->request('GET', '/en/player-profile/' . self::PLAYER);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME . ' – Speed Puzzler Profile – MySpeedPuzzling', $profile->filter('title')->text());
        self::assertSame(
            PlayerFixture::PLAYER_REGULAR_NAME . "'s speed puzzling profile: fastest jigsaw puzzle times, rankings, achievements and recent solves on MySpeedPuzzling.",
            $profile->filter('meta[name="description"]')->attr('content'),
        );
        self::assertSame('index, follow', $this->robots($profile));
    }

    public function testANameIsEscapedOnceInTheHead(): void
    {
        $this->browser();
        $playerId = $this->seedPlayerWithSoloResults(3, 'Tom & <Jerry>');

        $this->page($playerId, '');
        $html = (string) $this->browser()->getResponse()->getContent();

        self::assertStringContainsString('<title>Tom &amp; &lt;Jerry&gt; – Puzzle Results', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);

        $this->browser()->request('GET', '/en/player-profile/' . $playerId);
        self::assertStringContainsString('<title>Tom &amp; &lt;Jerry&gt; – Speed Puzzler Profile', (string) $this->browser()->getResponse()->getContent());
    }

    private function page(string $playerId, string $query): Crawler
    {
        $crawler = $this->browser()->request('GET', '/en/player-results/' . $playerId . $query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function robots(Crawler $crawler): string
    {
        $robots = $crawler->filter('meta[name="robots"]');
        self::assertCount(1, $robots);

        return (string) $robots->attr('content');
    }

    private function canonical(Crawler $crawler): string
    {
        $canonical = $crawler->filter('link[rel="canonical"]');
        self::assertCount(1, $canonical);

        return (string) $canonical->attr('href');
    }

    /**
     * @return array<string, string>
     */
    private function hreflang(Crawler $crawler): array
    {
        $links = [];

        foreach ($crawler->filter('link[rel="alternate"][hreflang]')->each(static fn (Crawler $link): array => [(string) $link->attr('hreflang'), (string) $link->attr('href')]) as [$language, $href]) {
            $links[$language] = $href;
        }

        return $links;
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }
}
