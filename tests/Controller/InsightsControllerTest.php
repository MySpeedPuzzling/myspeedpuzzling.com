<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Insights page (docs/features/insights/page.md): behind INSIGHTS_PUBLIC (admins always), always the viewer's own,
 * every section for a free and a member player - a free player's HTML carries locks with counts, never members-only
 * content - predictions switched off, the empty state, and the canaries (secret puzzles, suspicious results, other
 * players).
 */
final class InsightsControllerTest extends WebTestCase
{
    use ClonesSolvingTimes;
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'INSIGHTS_PUBLIC';
    private const string URL = '/en/insights';
    private const string FREE = PlayerFixture::PLAYER_REGULAR;
    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;

    /** Every section of the first response, in page order */
    private const array SECTIONS = ['profile', 'form', 'skills', 'expected', 'median', 'close-calls', 'learning', 'habits'];

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testWhileTheFlagIsOffAPlayerReachesNothingNew(): void
    {
        $browser = $this->browser(flag: false, player: self::FREE);

        $browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);

        $profile = $browser->request('GET', $this->profileUrl($browser, self::FREE));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $profile->filter('a.player-tab[href="' . self::URL . '"]'), 'no tab');
        self::assertCount(0, $profile->filter('[data-testid="menu-insights"]'), 'no menu row');
    }

    public function testAnAdminAlwaysSeesIt(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);

        $profile = $browser->request('GET', $this->profileUrl($browser, PlayerFixture::PLAYER_ADMIN));
        // In the header and in its compact bar
        self::assertCount(2, $profile->filter('a.player-tab[href="' . self::URL . '"]'));
        self::assertCount(1, $profile->filter('[data-testid="menu-insights"]'));

        $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        // Somebody else's profile never has the tab - Insights are self-only
        $other = $browser->request('GET', $this->profileUrl($browser, self::FREE));
        self::assertCount(0, $other->filter('a.player-tab[href="' . self::URL . '"]'));
    }

    public function testAGuestIsSentToSignIn(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();

        $browser->request('GET', self::URL);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testAFreePlayerGetsEverySectionAndLocksWithCounts(): void
    {
        $browser = $this->browser(flag: true, player: self::FREE);
        $this->growResults($browser, self::FREE, 40);

        $page = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $html = (string) $browser->getResponse()->getContent();

        $this->assertEverySection($page);
        self::assertCount(2, $page->filter('a.player-tab.active[href="' . self::URL . '"]'), 'the own profile tab, in the header and its compact bar');
        self::assertCount(1, $page->filter('[data-testid="insights-findings"]'));
        self::assertCount(1, $page->filter('[data-testid="insights-tiles"]'));

        // One lock per members-only part, each with the free trial or the membership in reach
        foreach (['skills', 'expected', 'outliers', 'learning', 'habits'] as $lock) {
            $element = $page->filter('[data-testid="insights-lock-' . $lock . '"]');
            self::assertCount(1, $element, $lock);
            self::assertCount(1, $element->filter('form[action$="/start-free-trial"], a[href$="/membership"], form button, a.btn'), $lock);
        }

        // Members-only content never reaches a free player's HTML
        self::assertCount(0, $page->filter('.ins-srow'), 'skill map rows');
        self::assertCount(0, $page->filter('[data-testid="insights-outliers-slow"], [data-testid="insights-outliers-fast"]'));
        self::assertCount(0, $page->filter('[data-testid="insights-breaks"], [data-testid="insights-time-of-day"]'));
        self::assertCount(0, $page->filter('[id^="ins-chart-expected"], [id^="ins-chart-learning"]'));
        self::assertCount(0, $page->filter('#insights-filters-modal'));

        $weekdays = $page->filter('[id^="ins-chart-weekdays"]');

        if ($weekdays->count() > 0) {
            self::assertStringNotContainsString('than your usual', (string) $weekdays->html(), 'pace per weekday is for members');
        }

        // Only the free periods are offered
        self::assertSame(['3m', '6m'], $page->filter('#insights-when option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertStringNotContainsString('Jane Smith', $html, 'no other player on the page');
    }

    /**
     * `?period=all` and the plain visit strip to the same filters, so a free player's two visits share one cached
     * report - the "for members" notice is this request's own, never the cached report's
     */
    public function testTheMembersNoticeBelongsToTheRequestNotTheCachedReport(): void
    {
        $browser = $this->browser(flag: true, player: self::FREE);
        $this->growResults($browser, self::FREE, 20);
        // One kernel for every request: the page cache (an array pool in tests) keeps what the first visit built
        $browser->disableReboot();

        $stripped = $browser->request('GET', self::URL . '?period=all');
        self::assertCount(1, $stripped->filter('.ins-filters__stripped'));

        $plain = $browser->request('GET', self::URL);
        self::assertCount(0, $plain->filter('.ins-filters__stripped'), 'the plain visit is served the cached report without the notice');

        $again = $browser->request('GET', self::URL . '?period=all&results=first');
        self::assertCount(1, $again->filter('.ins-filters__stripped'), 'a stripped link after a plain visit still says so');
        self::assertSame($this->resultCount($plain), $this->resultCount($again), 'the same report');
    }

    public function testAMemberGetsEverySectionWithoutLocks(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->growResults($browser, self::MEMBER, 40);

        $page = $browser->request('GET', self::URL . '?period=all');
        self::assertResponseIsSuccessful();

        $this->assertEverySection($page);
        self::assertCount(0, $page->filter('.ins-lock'), 'no locks for a member');
        self::assertCount(1, $page->filter('#insights-filters-modal'));
        self::assertContains('all', $page->filter('#insights-when option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocalesAndPlayers(): iterable
    {
        foreach (['cs', 'de', 'en', 'es', 'fr', 'ja'] as $locale) {
            yield $locale . ' free' => [$locale, self::FREE];
            yield $locale . ' member' => [$locale, self::MEMBER];
        }
    }

    #[DataProvider('provideLocalesAndPlayers')]
    public function testThePageRendersInEveryLanguage(string $locale, string $playerId): void
    {
        $browser = $this->browser(flag: true, player: $playerId);
        $this->growResults($browser, $playerId, 30);

        $page = $browser->request('GET', '/' . $locale . '/insights?period=all');
        self::assertResponseIsSuccessful();

        $this->assertEverySection($page);
        self::assertSame($locale, $page->filter('html')->attr('lang'));
    }

    /**
     * The daily card (player_insights_profile) through the whole page: the axes with their percentiles, the strongest
     * and the most room to grow, and the radar chart's data
     */
    public function testTheProfileCardRendersFromTheDailyRow(): void
    {
        $browser = $this->browser(flag: true, player: self::FREE);
        $this->growResults($browser, self::FREE, 20);
        $this->connection($browser)->executeStatement(
            "INSERT INTO player_insights_profile (player_id, computed_at,
                speed_score, speed_sample_size, speed_percentile,
                improvement_score, improvement_sample_size, improvement_percentile,
                consistency_score, consistency_sample_size, consistency_percentile,
                learning_score, learning_sample_size, learning_percentile,
                hard_puzzles_score, hard_puzzles_sample_size, hard_puzzles_percentile,
                big_puzzles_score, big_puzzles_sample_size, big_puzzles_percentile,
                events_score, events_sample_size, events_percentile)
             VALUES (:player, " . TestClock::SQL_NOW . ", 0.9, 40, 81.4, 0.05, 12, 62.0, 1.15, 30, 47.0, 0.12, 6, 55.5, NULL, 2, NULL, 1.02, 8, 23.6, NULL, 0, NULL)",
            ['player' => self::FREE],
        );

        $page = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $section = $page->filter('[data-testid="insights-section-profile"]');

        self::assertCount(7, $section->filter('.ins-axis'));
        self::assertSame(['81', '62', '47', '56', '–', '24', '–'], $section->filter('.ins-axis__value')->each(static fn (Crawler $value): string => trim($value->text())));
        self::assertStringContainsString('Your strongest side is Speed. Your most room to grow: Big puzzles.', $section->filter('.ins-take')->text());

        // The radar is a real chart with the card's numbers (null where an axis has too few results)
        $chart = $section->filter('[id^="ins-chart-profile"] canvas');
        self::assertCount(1, $chart);
        /** @var array{type: string, data: array{datasets: list<array{data: list<null|float|int>}>}} $view */
        $view = json_decode((string) $chart->attr('data-symfony--ux-chartjs--chart-view-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('radar', $view['type']);
        self::assertSame([81, 62, 47, 56, null, 24, null], array_map(static fn (null|float|int $value): null|int => $value === null ? null : (int) $value, $view['data']['datasets'][0]['data']));
    }

    public function testPredictionsSwitchedOffMeanNoExpectedTimes(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->growResults($browser, self::MEMBER, 20);
        $this->connection($browser)->executeStatement('UPDATE player SET time_predictions_opted_out = true WHERE id = :id', ['id' => self::MEMBER]);

        $page = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        self::assertCount(1, $page->filter('[data-testid="insights-predictions-off"]'));
        self::assertCount(1, $page->filter('[data-testid="insights-predictions-off"] a[href$="/edit-profile"], [data-testid="insights-predictions-off"] a'));
        self::assertCount(1, $page->filter('[data-testid="insights-nudge-predictions_off"]'));
        self::assertCount(0, $page->filter('[id^="ins-chart-expected"]'));
        self::assertCount(0, $page->filter('[data-testid="insights-finding-surprises"]'));
    }

    public function testFewerThanTenResultsGetTheEmptyState(): void
    {
        $browser = $this->browser(flag: true, player: self::FREE);
        $this->connection($browser)->executeStatement("UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :id AND puzzling_type = 'solo'", ['id' => self::FREE]);

        $page = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        self::assertCount(1, $page->filter('[data-testid="insights-empty"]'));
        self::assertCount(0, $page->filter('[data-testid="insights-section-profile"]'));
        self::assertCount(1, $page->filter('[data-testid="insights-nudge-more_results"]'));
    }

    public function testSecretPuzzlesAndSuspiciousResultsStayOut(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->growResults($browser, self::MEMBER, 30);

        $page = $browser->request('GET', self::URL . '?period=all');
        $before = $this->resultCount($page);

        // A puzzle of the player turns secret, another result is found suspicious
        $connection = $this->connection($browser);
        /** @var array{puzzle_id: string} $secret */
        $secret = $connection->fetchAssociative(
            "SELECT pst.puzzle_id FROM puzzle_solving_time pst
             WHERE pst.player_id = :id AND pst.puzzling_type = 'solo' ORDER BY pst.puzzle_id LIMIT 1",
            ['id' => self::MEMBER],
        );
        $secretResults = $connection->fetchOne(
            "SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND puzzle_id = :puzzle AND puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve > 0",
            ['id' => self::MEMBER, 'puzzle' => $secret['puzzle_id']],
        );
        $connection->executeStatement("UPDATE puzzle SET name = 'Secret canary puzzle', hide_until = " . TestClock::SQL_NOW . " + INTERVAL '1 year' WHERE id = :id", ['id' => $secret['puzzle_id']]);
        $connection->executeStatement(
            "UPDATE puzzle_solving_time SET suspicious = true WHERE id = (
                SELECT id FROM puzzle_solving_time WHERE player_id = :id AND puzzle_id <> :puzzle AND puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve > 0 ORDER BY id LIMIT 1
            )",
            ['id' => self::MEMBER, 'puzzle' => $secret['puzzle_id']],
        );

        $page = $browser->request('GET', self::URL . '?period=all');
        self::assertResponseIsSuccessful();

        self::assertIsNumeric($secretResults);
        self::assertSame($before - (int) $secretResults - 1, $this->resultCount($page));
        self::assertStringNotContainsString('Secret canary puzzle', (string) $browser->getResponse()->getContent());
        self::assertStringNotContainsString(strtolower($secret['puzzle_id']), strtolower((string) $browser->getResponse()->getContent()));
    }

    public function testSeeWhyLinksFromTheOwnRecapAndResultDetail(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $timeId = $this->growResults($browser, self::MEMBER, 1)[0];
        // Below InsightsReport::MIN_RESULTS: the page itself (its empty state says why), no section to jump to
        $this->connection($browser)->executeStatement(
            "UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :id AND puzzling_type = 'solo' AND id <> :time",
            ['id' => self::MEMBER, 'time' => $timeId],
        );

        $detail = $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertSame(self::URL, $detail->filter('[data-testid="result-see-why"]')->attr('href'));

        // Enough results: straight to the section that explains it (members: expected vs actual)
        $this->connection($browser)->executeStatement("UPDATE puzzle_solving_time SET suspicious = false WHERE player_id = :id", ['id' => self::MEMBER]);
        $this->growResults($browser, self::MEMBER, 12);

        $detail = $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertSame(self::URL . '#insights-expected', $detail->filter('[data-testid="result-see-why"]')->attr('href'));

        $recap = $browser->request('GET', '/en/time-added/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertSame(self::URL . '#insights-expected', $recap->filter('[data-testid="recap-see-why"]')->attr('href'));

        // Somebody else looking at that result gets no link
        TestingLogin::asPlayer($browser, self::FREE);
        $detail = $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $detail->filter('[data-testid="result-see-why"]'));
    }

    public function testNoSeeWhyWhileTheFlagHidesInsights(): void
    {
        $browser = $this->browser(flag: false, player: self::MEMBER);
        $timeId = $this->growResults($browser, self::MEMBER, 1)[0];

        $browser->request('GET', '/en/result/' . $timeId);
        self::assertCount(0, (new Crawler((string) $browser->getResponse()->getContent()))->filter('[data-testid="result-see-why"]'));

        $browser->request('GET', '/en/time-added/' . $timeId);
        self::assertCount(0, (new Crawler((string) $browser->getResponse()->getContent()))->filter('[data-testid="recap-see-why"]'));
    }

    private function assertEverySection(Crawler $page): void
    {
        foreach (self::SECTIONS as $section) {
            self::assertCount(1, $page->filter('[data-testid="insights-section-' . $section . '"]'), $section);
        }

        $order = $page->filter('[data-testid^="insights-section-"]')->each(static fn (Crawler $section): string => substr((string) $section->attr('data-testid'), strlen('insights-section-')));
        self::assertSame(self::SECTIONS, array_values(array_diff($order, ['nudges'])), 'the prototype\'s order');
    }

    private function resultCount(Crawler $page): int
    {
        return (int) preg_replace('/\D/', '', $page->filter('.ins-filters__row .ins-filters__count')->text());
    }

    private function browser(bool $flag, string $player): KernelBrowser
    {
        // Before the kernel boots - the flag is a container parameter
        $this->overrideFeatureFlagEnv(self::FLAG, $flag);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }

    /**
     * More solo results for the player: copies of their own over the last year, each puzzle given a median so the
     * results count everywhere
     *
     * @return list<string> the new time ids
     */
    private function growResults(KernelBrowser $browser, string $playerId, int $count): array
    {
        $connection = $this->connection($browser);

        /** @var list<string> $times */
        $times = $connection->fetchFirstColumn(
            "SELECT id::text FROM puzzle_solving_time WHERE player_id = :player AND puzzling_type = 'solo' AND seconds_to_solve > 0 ORDER BY id",
            ['player' => $playerId],
        );
        self::assertNotEmpty($times, 'the fixture player has solo results to copy');

        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $created[] = $this->cloneSolvingTime($times[$i % count($times)], [
                'days_ago' => ($i * 7) % 360 + 1,
                // Half of them first tries: "your usual" (the form) rests on first tries
                'first_attempt' => $i % 2 === 0,
                'seconds_to_solve' => 1800 + ($i * 137) % 1500,
            ]);
        }

        $connection->executeStatement(
            "UPDATE puzzle_statistics SET median_time_solo = 2400, solved_times_solo_count = GREATEST(solved_times_solo_count, 10)
             WHERE puzzle_id IN (SELECT puzzle_id FROM puzzle_solving_time WHERE player_id = :player)",
            ['player' => $playerId],
        );

        return $created;
    }

    private function profileUrl(KernelBrowser $browser, string $playerId): string
    {
        return $browser->getContainer()->get('router')->generate('player_profile', ['playerId' => $playerId, '_locale' => 'en']);
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
