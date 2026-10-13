<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
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

        // The Menu's Statistics row (MENU-01; the old user menu went with NAV-06) and the player header
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
     * PROF-04 (docs/features/navigation README §4.7): the average-time chart and the skill / MSP Rating cards are on
     * Statistics - under the header, above the period menu - and no longer on the profile
     *
     * @return iterable<string, array{null|string, string, list<string>, list<string>}>
     */
    public static function provideStandingVariants(): iterable
    {
        // viewer, player, what .profile-standing holds, what it does not
        yield 'a member sees both cards' => [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE, ['[data-live-name-value="PlayerSkillProfile"]', '[data-live-name-value="PlayerRatingProfile"]'], ['.bi-eye-slash', '.bi-incognito']];
        yield 'a guest sees the rating card' => [null, PlayerFixture::PLAYER_REGULAR, ['[data-live-name-value="PlayerRatingProfile"]'], ['[data-live-name-value="PlayerSkillProfile"]', '[data-bs-target="#membersExclusiveModal"]']];
        yield 'a non-member sees the rating card' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, ['[data-live-name-value="PlayerRatingProfile"]'], ['[data-live-name-value="PlayerSkillProfile"]']];
        yield 'the owner of a private profile is told why' => [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_PRIVATE, ['.bi-incognito'], ['[data-live-name-value="PlayerRatingProfile"]', '[data-live-name-value="PlayerSkillProfile"]']];
    }

    /**
     * @param list<string> $present
     * @param list<string> $absent
     */
    #[DataProvider('provideStandingVariants')]
    public function testTheStandingIsOnStatistics(null|string $viewer, string $playerId, array $present, array $absent): void
    {
        $browser = self::createClient();

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        $crawler = $browser->request('GET', '/en/player-statistics/' . $playerId);
        $this->assertResponseIsSuccessful();

        $standing = $crawler->filter('main .profile-standing');
        self::assertCount(1, $standing);

        foreach ($present as $selector) {
            self::assertGreaterThan(0, $standing->filter($selector)->count(), $selector);
        }

        foreach ($absent as $selector) {
            self::assertCount(0, $standing->filter($selector), $selector);
        }

        // The chart in its own section; chart, standing and the period menu in this order, under the header
        self::assertCount(1, $crawler->filter('main .player-standing-chart [data-live-name-value="Chart:PlayerAverageTimeChart"]'));
        $html = (string) $browser->getResponse()->getContent();
        $header = strpos($html, 'class="player-header');
        $chart = strpos($html, 'player-standing-chart');
        $cards = strpos($html, 'class="profile-standing');
        $period = strpos($html, 'dropdown-toggle', (int) $cards);
        self::assertNotFalse($header);
        self::assertTrue($header < $chart && $chart < $cards && $cards < $period, 'header < chart < cards < period menu');

        // Two "1st tries only" switches now share the page (the chart's and the statistics') - every id once
        $ids = $crawler->filter('[id]')->each(static fn (Crawler $element): string => (string) $element->attr('id'));
        self::assertSame([], array_keys(array_filter(array_count_values($ids), static fn (int $count): bool => $count > 1)), 'Duplicate ids');

        // The profile has neither any more
        $profile = $browser->request('GET', '/en/player-profile/' . $playerId);
        self::assertCount(0, $profile->filter('.profile-standing, [data-live-name-value="Chart:PlayerAverageTimeChart"], [data-live-name-value="PlayerRatingProfile"], [data-live-name-value="PlayerSkillProfile"]'));
    }

    /**
     * Opted out of rankings: the owner is told where to change it, everybody else sees no card at all
     */
    public function testRankingOptOutOnStatistics(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_WITH_STRIPE);
        $standing = $crawler->filter('main .profile-standing');
        self::assertCount(2, $standing->filter('.bi-eye-slash'));
        self::assertCount(2, $standing->filter('a[href="/en/edit-profile"]'));
        self::assertCount(0, $standing->filter('[data-live-name-value="PlayerRatingProfile"]'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame('', trim($crawler->filter('main .profile-standing')->text()));
        // The chart stays: it is the player's own times, not a ranking
        self::assertCount(1, $crawler->filter('main [data-live-name-value="Chart:PlayerAverageTimeChart"]'));
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
