<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use SpeedPuzzling\Web\Tests\Navigation\PlayerProfileQueryBudgetTest;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a player's Results (/en/player-results/{id}, docs/features/navigation PROF-05a) cost in statements - chrome
 * included, like PlayerProfileQueryBudgetTest - for the page and for its frame (`Turbo-Frame: player-results`). The
 * whole history is loaded and filtered, sorted and paged in PHP, so the count does not depend on how many results the
 * player has (a fixture player with 9 puzzles and with 69 cost the same) nor on the page; the search costs exactly one
 * statement more (PROF-05d). Each row stays at or below the profile's (the Overview's) current pin for the same viewer
 * in PlayerProfileQueryBudgetTest, read from its provider (PROF review Q6): the list moved off the profile, and Results
 * never costs more than the page it was moved from does now.
 *
 * Pinned exactly; budgets only go down, every change commented with the requirement ID. The second request is counted
 * (the first warms the caches and claims an announcement modal).
 *
 * PROF-06: exactly one rank statement, on By-puzzle Solo rows only - the Best rank sort included (allForPlayer() reads
 * every rank and forPuzzles() takes the shown rows' from its memo); Every result and the Pairs/Teams tabs none.
 * PROF-05b: Show more's stream (`?fragment=more`) has its own row - no page around it.
 */
final class PlayerResultsQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, string, bool, int, string}>
     */
    public static function provideViews(): iterable
    {
        // [viewer, player, frame only, statements, the same view's row of PlayerProfileQueryBudgetTest (the Overview)]
        // The player's profile row, the history (GetPlayerSolvedPuzzles: the player once, solo, pairs + their members,
        // teams + their members) and the shown rows' ranks
        yield 'guest → other, page' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_REGULAR, false, 7, 'guest → other'];
        yield 'guest → other, frame' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_REGULAR, true, 7, 'guest → other'];
        // + the signed-in chrome on the page (user refresh, the viewer's profile row, the Inbox badge), in the frame the
        // first two
        yield 'player → other, page' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_REGULAR, false, 10, 'player → other'];
        yield 'player → other, frame' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_REGULAR, true, 9, 'player → other'];
        // + the members' difficulty
        yield 'member → other, page' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_REGULAR, false, 11, 'member → other'];
        yield 'member → other, frame' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_REGULAR, true, 10, 'member → other'];
        // Own results: the player's profile row is the viewer's
        yield 'member → own, page' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_WITH_STRIPE, false, 10, 'member → own'];
        yield 'member → own, frame' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_WITH_STRIPE, true, 9, 'member → own'];
    }

    #[DataProvider('provideViews')]
    public function testTheResultsCost(string $role, string $playerId, bool $frameOnly, int $statements, string $overviewView): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);
        $url = '/en/player-results/' . $playerId;

        self::assertSame($statements, $this->statements($browser, $url, $frameOnly), $role . ' ' . $url . " ran:\n" . implode("\n", $this->executedSql($browser)));
        self::assertLessThanOrEqual(self::overviewPin($overviewView, $playerId), $statements, 'Results cost no more than the profile (the Overview) for the same viewer');

        // The search is one statement more (PROF-05d); another sort costs the same, Best rank too (PROF-06: one rank
        // statement, the memo); Every result and the Pairs tab one less - their rows carry no rank (PROF-06)
        self::assertSame($statements + 1, $this->statements($browser, $url . '?q=puzzle', $frameOnly), 'PROF-05d: the search is one statement');
        self::assertSame($statements, $this->statements($browser, $url . '?sort=name&first=1&pieces=500', $frameOnly));
        self::assertSame($statements, $this->statements($browser, $url . '?sort=rank', $frameOnly), 'PROF-06: Best rank reads every rank in the one rank statement');
        self::assertSame($statements - 1, $this->statements($browser, $url . '?view=every&sort=oldest', $frameOnly), 'PROF-06: no rank on Every result');
        self::assertSame($statements - 1, $this->statements($browser, $url . '?category=duo', $frameOnly), 'PROF-06: no pair/team rank this release');
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideStreams(): iterable
    {
        // [viewer, player, statements] - Show more's stream (PROF-05b) costs what the frame does: the player's profile
        // row, the history and the appended rows' ranks (PROF-06); signed in + the user refresh and the viewer's
        // profile row, members + the difficulty. No page around it: no Inbox badge
        yield 'guest → other' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_REGULAR, 7];
        yield 'player → other' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_REGULAR, 9];
        yield 'member → other' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_REGULAR, 10];
        yield 'member → own' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_WITH_STRIPE, 9];
    }

    /**
     * The stream appending page 2 of a history with 60 more puzzles costs what appending page 1 of the fixture's did
     */
    #[DataProvider('provideStreams')]
    public function testTheShowMoreStreamCost(string $role, string $playerId, int $statements): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);
        $url = '/en/player-results/' . $playerId . '?fragment=more';

        $short = $this->statements($browser, $url . '&page=1', false);
        $this->addSoloResults(60, $playerId);
        $long = $this->statements($browser, $url . '&page=2', false);

        self::assertSame($statements, $long, $role . " ran:\n" . implode("\n", $this->executedSql($browser)));
        self::assertSame($short, $long, 'The cost does not depend on the history');
        // Under Every result one less: no rank (PROF-06)
        self::assertSame($statements - 1, $this->statements($browser, $url . '&page=2&view=every', false));
    }

    /**
     * A history ten times longer costs the same - for a guest and a member, the page and its frame
     */
    public function testTheCostDoesNotDependOnTheHistorysSize(): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::MEMBER);
        $url = '/en/player-results/' . PlayerFixture::PLAYER_REGULAR;

        $before = [
            'page' => $this->statements($browser, $url, false),
            'frame' => $this->statements($browser, $url, true),
            'every result, fastest' => $this->statements($browser, $url . '?view=every&sort=fastest', false),
            'best rank' => $this->statements($browser, $url . '?sort=rank', false),
        ];

        $this->addSoloResults(60);

        $after = [
            // Rows 1…100 of the longer history
            'page' => $this->statements($browser, $url . '?page=2', false),
            'frame' => $this->statements($browser, $url, true),
            'every result, fastest' => $this->statements($browser, $url . '?view=every&sort=fastest', false),
            'best rank' => $this->statements($browser, $url . '?sort=rank', false),
        ];

        self::assertSame($before, $after);
        // Best rank reads every rank in the one rank statement the shown rows' ranks would cost
        self::assertSame($before['page'], $before['best rank']);
    }

    /**
     * The Overview's current pin for a view (PlayerProfileQueryBudgetTest::provideViews(): [viewer, player, pin])
     */
    private static function overviewPin(string $view, string $playerId): int
    {
        foreach (PlayerProfileQueryBudgetTest::provideViews() as $name => [, $profilePlayerId, $pin]) {
            if ($name === $view) {
                self::assertSame($playerId, $profilePlayerId, 'The Overview row is the same player\'s');

                return $pin;
            }
        }

        self::fail('PlayerProfileQueryBudgetTest has no row "' . $view . '"');
    }

    private function statements(KernelBrowser $browser, string $url, bool $frameOnly): int
    {
        $server = $frameOnly ? ['HTTP_TURBO_FRAME' => 'player-results'] : [];

        $browser->request('GET', $url, server: $server);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url, server: $server);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    /**
     * Copies of one of the player's solved puzzles, each with his solo time (rolled back with the test's transaction)
     */
    private function addSoloResults(int $copies, string $playerId = PlayerFixture::PLAYER_REGULAR): void
    {
        $database = self::getContainer()->get(Connection::class);
        $timeId = $database->fetchOne(
            "SELECT id FROM puzzle_solving_time WHERE player_id = :playerId AND puzzling_type = 'solo' AND seconds_to_solve IS NOT NULL ORDER BY puzzle_id = :puzzleId DESC, id LIMIT 1",
            ['playerId' => $playerId, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        $puzzleId = $database->fetchOne('SELECT puzzle_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        $database->executeStatement(
            'CREATE TEMP TABLE copy_puzzle ON COMMIT DROP AS SELECT puzzle.*, g AS copy FROM puzzle, generate_series(1, :copies) g WHERE puzzle.id = :puzzleId',
            ['copies' => $copies, 'puzzleId' => $puzzleId],
        );
        $database->executeStatement("UPDATE copy_puzzle SET id = gen_random_uuid(), name = 'Copy ' || copy");
        $database->executeStatement(
            'CREATE TEMP TABLE copy_time ON COMMIT DROP AS SELECT pst.*, cp.id AS copy_puzzle_id FROM puzzle_solving_time pst, copy_puzzle cp WHERE pst.id = :timeId',
            ['timeId' => $timeId],
        );
        $database->executeStatement('UPDATE copy_time SET id = gen_random_uuid(), puzzle_id = copy_puzzle_id');
        $database->executeStatement('ALTER TABLE copy_puzzle DROP COLUMN copy');
        $database->executeStatement('ALTER TABLE copy_time DROP COLUMN copy_puzzle_id');
        $database->executeStatement('INSERT INTO puzzle SELECT * FROM copy_puzzle');
        $database->executeStatement('INSERT INTO puzzle_solving_time SELECT * FROM copy_time');
    }
}
