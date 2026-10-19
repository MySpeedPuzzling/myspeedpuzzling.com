<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

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
        $connection->executeStatement("UPDATE puzzle SET name = 'Secret canary puzzle', hide_until = NOW() + INTERVAL '1 year' WHERE id = :id", ['id' => $secret['puzzle_id']]);
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

        $detail = $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $detail->filter('[data-testid="result-see-why"][href^="' . self::URL . '"]'));

        $recap = $browser->request('GET', '/en/time-added/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $recap->filter('[data-testid="recap-see-why"][href^="' . self::URL . '"]'));

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
