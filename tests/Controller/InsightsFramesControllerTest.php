<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\InsightsLadderSeeding;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The lazy frames of the Insights page (docs/features/insights/page.md "Lazy frames"): behind INSIGHTS_PUBLIC like the
 * page, always the viewer's own data, every frame for a free and a member player - a free player's HTML carries one
 * lock per section with counts, never members-only values - time predictions switched off, rankings opted out, and
 * the canaries (secret puzzles, suspicious results, a hidden teammate). The fixture players join the insights ladder
 * (InsightsLadderSeeding) so the MSP rating, the radar, a pair and the pace history all have something to show.
 */
final class InsightsFramesControllerTest extends WebTestCase
{
    use InsightsLadderSeeding;
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'INSIGHTS_PUBLIC';
    private const string FREE = PlayerFixture::PLAYER_REGULAR;
    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;

    /** Frame route => the sections it renders */
    private const array FRAMES = [
        '/en/insights/rating' => ['rating', 'forecast'],
        '/en/insights/re-solve' => ['resolve'],
        '/en/insights/pairs-and-teams' => ['pairs'],
        '/en/insights/improvement' => ['improvement'],
    ];

    private const string PAIR_NAME = 'Canary Pair Name';

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testWhileTheFlagIsOffEveryFrameIsNotFound(): void
    {
        $browser = $this->browser(flag: false, player: self::MEMBER);

        foreach (array_keys(self::FRAMES) as $url) {
            $this->frame($browser, $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testAGuestIsSentToSignIn(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();

        foreach (array_keys(self::FRAMES) as $url) {
            $this->frame($browser, $url);
            self::assertResponseRedirects(null, null, $url);
            self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
        }
    }

    public function testAFrameOpenedOnItsOwnGoesToItsSection(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);

        $browser->request('GET', '/en/insights/pairs-and-teams?pieces=500&brands[]=x');

        self::assertResponseRedirects('/en/insights?pieces=500#insights-pairs');
    }

    public function testTheFramesThePageAsksForAreTheOnesThatAnswer(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->joinLadder($browser, self::MEMBER);

        $page = $browser->request('GET', '/en/insights?pieces=500');
        self::assertResponseIsSuccessful();

        foreach (['rating', 'resolve', 'pairs', 'improvement'] as $part) {
            $frame = $page->filter('turbo-frame[data-testid="insights-frame-' . $part . '"]');
            self::assertCount(1, $frame, $part);
            self::assertSame('lazy', $frame->attr('loading'));
            self::assertSame('_top', $frame->attr('target'));
            self::assertNotNull($frame->attr('data-live-ignore'));

            $id = (string) $frame->attr('id');
            $src = (string) $frame->attr('src');
            $answer = $this->frame($browser, $src, $id);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $answer->filter('turbo-frame#' . $id), $part);
            self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('private', (string) $browser->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));
        }

        // The rating reads no filter of the page, the others only theirs
        self::assertSame('/en/insights/rating', $page->filter('turbo-frame[data-testid="insights-frame-rating"]')->attr('src'));
        self::assertSame('/en/insights/re-solve?pieces=500', $page->filter('turbo-frame[data-testid="insights-frame-resolve"]')->attr('src'));

        // The placeholders carry the chips' anchors, no section test id of the first response
        foreach (['rating', 'forecast', 'resolve', 'pairs', 'improvement'] as $anchor) {
            self::assertCount(1, $page->filter('#insights-' . $anchor), $anchor);
            self::assertCount(1, $page->filter('.ins-asks a[href="#insights-' . $anchor . '"]'), $anchor);
        }
    }

    public function testAMemberGetsEveryFrame(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->joinLadder($browser, self::MEMBER);

        $frames = $this->everyFrame($browser);

        $rating = $frames['/en/insights/rating'];
        self::assertCount(0, $rating->filter('.ins-lock'), 'no locks for a member');
        self::assertStringContainsString("today's ladder", $rating->filter('[data-testid="insights-rating-takeaway"]')->text());
        self::assertGreaterThan(0, $rating->filter('[data-testid="insights-rating-moves"] .ins-moves__list--points li')->count(), 'moves');
        self::assertCount(1, $rating->filter('[data-testid="insights-rating-strips"]'));
        self::assertGreaterThan(0, $rating->filter('[data-testid="insights-rating-strips"] rect')->count());
        self::assertCount(1, $rating->filter('[id^="ins-chart-forecast-"]'), 'forecast chart');
        self::assertGreaterThan(0, $rating->filter('[data-testid="insights-forecast-milestones"] li')->count());

        $resolve = $frames['/en/insights/re-solve'];
        self::assertGreaterThan(0, $resolve->filter('[data-testid="insights-resolve-list"] li')->count(), 'old puzzles to re-solve');
        self::assertCount(1, $resolve->filter('[id^="ins-chart-resolve-"]'));
        self::assertCount(1, $resolve->filter('a[href*="/en/what-to-solve-next"]'), 'the Puzzle Picker');

        $pairs = $frames['/en/insights/pairs-and-teams'];
        self::assertCount(1, $pairs->filter('[data-testid="insights-pairs-pairs"] .ins-srow'));
        self::assertCount(0, $pairs->filter('[data-testid="insights-pairs-teams"]'), 'pairs and teams are never mixed');
        self::assertStringContainsString(self::PAIR_NAME, (string) $pairs->html());

        $improvement = $frames['/en/insights/improvement'];
        self::assertCount(1, $improvement->filter('[id^="ins-chart-improvement-"]'), 'pace curve');
        self::assertCount(1, $improvement->filter('[data-testid="insights-rating-over-time"]'));
    }

    public function testAFreePlayerGetsOneLockPerSectionWithCounts(): void
    {
        $browser = $this->browser(flag: true, player: self::FREE);
        $this->joinLadder($browser, self::FREE);

        $frames = $this->everyFrame($browser);

        $locks = [
            '/en/insights/rating' => ['rating', 'forecast'],
            '/en/insights/re-solve' => ['resolve'],
            '/en/insights/pairs-and-teams' => ['pairs'],
            '/en/insights/improvement' => ['improvement'],
        ];

        foreach ($locks as $url => $sections) {
            foreach ($sections as $section) {
                $lock = $frames[$url]->filter('[data-testid="insights-lock-' . $section . '"]');
                self::assertCount(1, $lock, $section);
                self::assertMatchesRegularExpression('/\d/', $lock->filter('.ins-lock__title')->text(), $section . ': real counts');
                self::assertCount(1, $lock->filter('form button, a.btn'), $section . ': the trial or the membership in reach');
            }
        }

        // Members-only content never reaches a free player's HTML
        $html = implode("\n", array_map(static fn (Crawler $frame): string => (string) $frame->html(), $frames));
        self::assertStringNotContainsString('ins-moves', $html, 'moves');
        self::assertStringNotContainsString('insights-rating-strips', $html, 'portfolio strips');
        self::assertStringNotContainsString('insights-ladder-path', $html);
        self::assertStringNotContainsString('ins-chart', $html, 'charts');
        self::assertStringNotContainsString('data-chart', $html);
        self::assertDoesNotMatchRegularExpression('/\d{2}:\d{2}:\d{2}/', $html, 'no time at all: expected times, best times, paces');
        self::assertStringNotContainsString('ins-srow', $html, 'pair rows');
        self::assertStringNotContainsString(self::PAIR_NAME, $html, 'who brings out the best is for members');
        self::assertStringNotContainsString('insights-improvement-jumps', $html);
        self::assertStringNotContainsString('Insights Puzzle', $html, 'no puzzle of a suggestion');
    }

    public function testPredictionsSwitchedOffMeanNoExpectedTimes(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->joinLadder($browser, self::MEMBER);
        $this->connection($browser)->executeStatement('UPDATE player SET time_predictions_opted_out = true WHERE id = :id', ['id' => self::MEMBER]);

        $rating = $this->frame($browser, '/en/insights/rating');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $rating->filter('[data-testid="insights-section-rating"] [data-testid="insights-predictions-off"] a[href$="/edit-profile"]'));
        self::assertCount(0, $rating->filter('[data-testid="insights-rating-moves"]'));
        self::assertCount(1, $rating->filter('[data-testid="insights-forecast-predictions-off"]'));
        self::assertStringNotContainsString('best moves', $rating->filter('[data-testid="insights-section-forecast"] .ins-legend')->text());
        self::assertStringNotContainsString('likely', (string) $rating->html());
        // The rating itself and its entries stay
        self::assertCount(1, $rating->filter('[data-testid="insights-rating-strips"]'));

        $resolve = $this->frame($browser, '/en/insights/re-solve');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $resolve->filter('[data-testid="insights-predictions-off"] a[href$="/edit-profile"]'));
        self::assertCount(0, $resolve->filter('[data-testid="insights-resolve-list"]'));
    }

    public function testOptedOutOfRankingsMeansARatingWithoutALadderPlace(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->joinLadder($browser, self::MEMBER);
        $this->connection($browser)->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => self::MEMBER]);

        $rating = $this->frame($browser, '/en/insights/rating');
        self::assertResponseIsSuccessful();

        $takeaway = $rating->filter('[data-testid="insights-rating-takeaway"]')->text();
        self::assertStringContainsString('chose not to appear in rankings', $takeaway);
        self::assertStringNotContainsString('#', $takeaway, 'no place');
        self::assertCount(0, $rating->filter('[data-testid="insights-ladder-path"], [data-testid="insights-milestone-ladder"]'));
    }

    public function testSecretPuzzlesSuspiciousResultsAndHiddenTeammatesStayOut(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->joinLadder($browser, self::MEMBER, beforeRecalculating: function (Connection $connection): void {
            // A shelf puzzle and an old solved puzzle turn secret
            $connection->executeStatement(
                "UPDATE puzzle SET name = 'Secret canary ' || id, hide_until = NOW() + INTERVAL '1 year' WHERE id IN (:shelf, :solved)",
                ['shelf' => self::ladderPuzzle(41), 'solved' => self::ladderPuzzle(40)],
            );
            // The partner is private and has not let the member see them
            $connection->executeStatement("UPDATE player SET is_private = true, name = 'Hidden canary partner' WHERE id = :id", ['id' => self::ladderPlayer(2)]);
            $connection->executeStatement('UPDATE puzzling_team SET name = NULL');
            // An old result found suspicious
            $connection->executeStatement(
                "UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :player AND puzzle_id = :puzzle",
                ['player' => self::MEMBER, 'puzzle' => self::ladderPuzzle(39)],
            );
        });

        $frames = $this->everyFrame($browser);
        $html = implode("\n", array_map(static fn (Crawler $frame): string => (string) $frame->html(), $frames));

        self::assertStringNotContainsString('Secret canary', $html);
        self::assertStringNotContainsString(self::ladderPuzzle(41), $html);
        self::assertStringNotContainsString(self::ladderPuzzle(40), $html);
        self::assertStringNotContainsString(self::ladderPuzzle(39), $html, 'a suspicious result is no candidate and no entry');
        self::assertStringNotContainsString('Hidden canary partner', $html);
        self::assertStringNotContainsString(self::ladderPlayer(2), $html);
        self::assertStringContainsString('Hidden Puzzler', $frames['/en/insights/pairs-and-teams']->filter('[data-testid="insights-pairs-pairs"]')->text());
    }

    public function testOnlyTheViewersOwnData(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->joinLadder($browser, self::MEMBER);

        // Another player signed in gets their own (empty) pairs - nothing of the member's
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $pairs = $this->frame($browser, '/en/insights/pairs-and-teams?period=all');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::PAIR_NAME, (string) $pairs->html());

        $rating = $this->frame($browser, '/en/insights/rating');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Insights Puzzle 41', (string) $rating->html(), 'the member\'s shelf');
    }

    /**
     * @return array<string, Crawler> url => frame
     */
    private function everyFrame(KernelBrowser $browser): array
    {
        $frames = [];

        foreach (self::FRAMES as $url => $sections) {
            $frame = $this->frame($browser, $url . ($url === '/en/insights/rating' ? '' : '?period=all'));
            self::assertResponseIsSuccessful($url);

            foreach ($sections as $section) {
                self::assertCount(1, $frame->filter('turbo-frame [data-testid="insights-section-' . $section . '"]'), $section);
                self::assertCount(1, $frame->filter('#insights-' . $section), $section);
            }

            $frames[$url] = $frame;
        }

        return $frames;
    }

    private function frame(KernelBrowser $browser, string $url, string $frameId = 'insights-frame'): Crawler
    {
        return $browser->request('GET', $url, server: ['HTTP_TURBO_FRAME' => $frameId]);
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
     * The fixture player joins the insights ladder: first tries on ladder puzzles 1-40 over the last 19 months, faster
     * lately (so old puzzles would go faster now), puzzles 41-45 on the shelf, and a named pair with ladder player 2 -
     * then the 15-minute batch runs.
     *
     * @param null|callable(Connection): void $beforeRecalculating
     */
    private function joinLadder(KernelBrowser $browser, string $playerId, null|callable $beforeRecalculating = null): void
    {
        $connection = $this->connection($browser);
        $now = new DateTimeImmutable('today 12:00:00');
        $this->seedLadder($connection, $now);

        $connection->executeStatement(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt)
            SELECT gen_random_uuid(), :player, CAST('019b0001-0000-7000-8000-' || lpad(z::text, 12, '0') AS uuid),
                1250 + (30 + z * 14) * 2,
                CAST(:today AS timestamp) + INTERVAL '3 hours' - make_interval(days => 30 + z * 14),
                CAST(:today AS timestamp) + INTERVAL '3 hours' - make_interval(days => 30 + z * 14),
                true, true
            FROM generate_series(1, 40) AS z",
            ['player' => $playerId, 'today' => $now->format('Y-m-d')],
        );

        foreach (range(41, 45) as $puzzle) {
            $connection->executeStatement(
                'INSERT INTO collection_item (id, collection_id, player_id, puzzle_id, comment, added_at) VALUES (:id, NULL, :player, :puzzle, NULL, NOW())',
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'puzzle' => self::ladderPuzzle($puzzle)],
            );
        }

        $partner = self::ladderPlayer(2);
        $team = Uuid::uuid7()->toString();
        $connection->executeStatement(
            'INSERT INTO puzzling_team (id, composition_key, size, created_at, name) VALUES (:id, :key, 2, NOW(), :name)',
            ['id' => $team, 'key' => sha1($team), 'name' => self::PAIR_NAME],
        );

        foreach ([$playerId, $partner] as $position => $member) {
            $connection->executeStatement(
                'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:id, :team, :key, :player, NULL, :position)',
                ['id' => Uuid::uuid7()->toString(), 'team' => $team, 'key' => $member, 'player' => $member, 'position' => $position],
            );
        }

        $connection->executeStatement(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, puzzling_type, puzzlers_count, puzzling_team_id)
            SELECT gen_random_uuid(), :player, CAST('019b0001-0000-7000-8000-' || lpad((45 + z)::text, 12, '0') AS uuid), 900 + z * 7,
                CAST(:today AS timestamp) + INTERVAL '3 hours' - make_interval(days => 10 + z * 9),
                CAST(:today AS timestamp) + INTERVAL '3 hours' - make_interval(days => 10 + z * 9),
                true, true, 'duo', 2, :team
            FROM generate_series(1, 8) AS z",
            ['player' => $playerId, 'team' => $team, 'today' => $now->format('Y-m-d')],
        );

        if ($beforeRecalculating !== null) {
            $beforeRecalculating($connection);
        }

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $browser->getContainer()->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
