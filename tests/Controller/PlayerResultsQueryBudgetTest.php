<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a player's Results (/en/player-results/{id}, docs/features/navigation PROF-05a) cost in statements - chrome
 * included, like PlayerProfileQueryBudgetTest - for the page and for its frame (`Turbo-Frame: player-results`). The
 * whole history is loaded and filtered, sorted and paged in PHP, so the count does not depend on how many results the
 * player has (a fixture player with 9 puzzles and with 69 cost the same) nor on the page; the search costs exactly one
 * statement more (PROF-05d). Each row stays at or below the profile's P0-02 pin for the same viewer (the Results list
 * is the profile's list without the rest of the profile).
 *
 * Pinned exactly; budgets only go down, every change commented with the requirement ID. The second request is counted
 * (the first warms the caches and claims an announcement modal).
 *
 * The page with Show more's stream (`?fragment=more`) comes with PROF-05b.
 */
final class PlayerResultsQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, string, bool, int, int}>
     */
    public static function provideViews(): iterable
    {
        // [viewer, player, frame only, statements, the profile's pin for the same view (PlayerProfileQueryBudgetTest)]
        // The player's profile row, the history (GetPlayerSolvedPuzzles: the player once, solo, pairs + their members,
        // teams + their members) and the shown rows' ranks
        yield 'guest → other, page' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_REGULAR, false, 7, 14];
        yield 'guest → other, frame' => [NavigationViewer::GUEST, PlayerFixture::PLAYER_REGULAR, true, 7, 14];
        // + the signed-in chrome on the page (user refresh, the viewer's profile row, the Inbox badge), in the frame the
        // first two
        yield 'player → other, page' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_REGULAR, false, 10, 18];
        yield 'player → other, frame' => [NavigationViewer::PLAYER, PlayerFixture::PLAYER_REGULAR, true, 9, 18];
        // + the members' difficulty
        yield 'member → other, page' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_REGULAR, false, 11, 23];
        yield 'member → other, frame' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_REGULAR, true, 10, 23];
        // Own results: the player's profile row is the viewer's
        yield 'member → own, page' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_WITH_STRIPE, false, 10, 22];
        yield 'member → own, frame' => [NavigationViewer::MEMBER, PlayerFixture::PLAYER_WITH_STRIPE, true, 9, 22];
    }

    #[DataProvider('provideViews')]
    public function testTheResultsCost(string $role, string $playerId, bool $frameOnly, int $statements, int $profilePin): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);
        $url = '/en/player-results/' . $playerId;

        self::assertSame($statements, $this->statements($browser, $url, $frameOnly), $role . ' ' . $url . " ran:\n" . implode("\n", $this->executedSql($browser)));
        self::assertLessThanOrEqual($profilePin, $statements, 'Results cost no more than the profile');

        // The search is one statement more (PROF-05d); another sort costs the same; Every result one less - its rows
        // carry no rank (PROF-06)
        self::assertSame($statements + 1, $this->statements($browser, $url . '?q=puzzle', $frameOnly), 'PROF-05d: the search is one statement');
        self::assertSame($statements, $this->statements($browser, $url . '?sort=name&first=1&pieces=500', $frameOnly));
        self::assertSame($statements - 1, $this->statements($browser, $url . '?view=every&sort=oldest', $frameOnly));
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
    private function addSoloResults(int $copies): void
    {
        $database = self::getContainer()->get(Connection::class);
        $timeId = $database->fetchOne(
            "SELECT id FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId AND puzzling_type = 'solo' ORDER BY id LIMIT 1",
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        $database->executeStatement(
            'CREATE TEMP TABLE copy_puzzle ON COMMIT DROP AS SELECT puzzle.*, g AS copy FROM puzzle, generate_series(1, :copies) g WHERE puzzle.id = :puzzleId',
            ['copies' => $copies, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
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
