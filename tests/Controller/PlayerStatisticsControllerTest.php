<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PlayerStatisticsControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
    }

    /**
     * Every ?month=&year= period used to be crawled as its own noindex URL (a crawl trap):
     * the page is noindex, nofollow and every link to a statistics page is rel="nofollow".
     */
    public function testPageIsNoindexNofollowAndSoAreLinksToIt(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '?month=6&year=2024');

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        // The period dropdown (all time, years, months) and the player header
        $links = $crawler->filter('a[href^="/en/player-statistics/"]');
        self::assertGreaterThan(3, $links->count());
        self::assertSame(array_fill(0, $links->count(), 'nofollow'), $links->each(static fn (Crawler $link): null|string => $link->attr('rel')));
    }

    public function testMenuLinkToOwnStatisticsIsNofollow(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();

        // The signed-in user menu and the player header
        $links = $crawler->filter('a[href="/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '"]');
        self::assertGreaterThanOrEqual(2, $links->count());
        self::assertSame(array_fill(0, $links->count(), 'nofollow'), $links->each(static fn (Crawler $link): null|string => $link->attr('rel')));
    }

    public function testNonsensicalYearRedirectsToCanonicalUrl(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '?month=6&year=783');

        $this->assertResponseRedirects('/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR);
    }

    public function testMonthWithoutYearRedirectsToCanonicalUrl(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '?month=6');

        $this->assertResponseRedirects('/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR);
    }

    public function testValidPeriodIsNotRedirected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '?month=6&year=2024');

        $this->assertResponseIsSuccessful();
    }

    public function testShowAllIsNotRedirected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '?show-all=1');

        $this->assertResponseIsSuccessful();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideMalformedQueries(): array
    {
        // Payloads vulnerability scanners sent to production - each used to answer 400 (logged as an error)
        return [
            'SQL injection in show-all' => ["show-all=1' AND 1=1 UNION SELECT NULL-- -"],
            'template injection in year' => ['year=${903093104+922406280}'],
            'quote after the month' => ["month=1'&year=2025"],
            'quote after the year' => ['year=2025"'],
            'array instead of a value' => ['year[]=2024'],
        ];
    }

    #[DataProvider('provideMalformedQueries')]
    public function testMalformedQueryRedirectsToCanonicalUrl(string $query): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '?' . $query);

        $this->assertResponseRedirects('/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR);
    }
}
