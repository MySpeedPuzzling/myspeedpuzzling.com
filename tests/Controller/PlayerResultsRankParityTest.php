<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The rank on a player's Results rows (docs/features/navigation PROF-06, GetRanking::forPuzzles() / allForPlayer()) is
 * the player's position on the puzzle's own leaderboard (PuzzleTimes on the puzzle page) - for the same viewer: a
 * player the viewer blocked is on neither, a private player the viewer may see is on both, a suspicious time counts on
 * neither (the player's own best then is the next attempt). Pattern of GetRankingTest::testRankEqualsTheLeaderboard,
 * read from the rendered pages.
 *
 * Pairs/teams have no rank this release (decision 69, docs/TODO.md).
 */
final class PlayerResultsRankParityTest extends WebTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    // The player's fastest solo time on PUZZLE_500_01 (1750 s) - his next attempt (1800 s) ranks instead
    private const string PLAYERS_BEST_TIME = '018d0006-0000-0000-0000-000000000036';

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest (the private player is hidden)' => [null];
        // Blocks the admin, faster than the player on two of his puzzles; the private player's allow list names him
        yield 'a viewer who blocked a faster co-solver' => [PlayerFixture::PLAYER_WITH_FAVORITES];
        yield 'the player himself' => [self::PLAYER];
    }

    #[DataProvider('provideViewers')]
    public function testEveryRankChipIsThePositionOnThePuzzlesLeaderboard(null|string $viewerId): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_ADMIN],
        );
        $database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => self::PLAYERS_BEST_TIME]);
        // A faster co-solver's only time on PUZZLE_500_02 (the private player's) - off the leaderboard
        $database->executeStatement(
            "UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :player AND puzzle_id = :puzzle AND puzzling_type = 'solo'",
            ['player' => PlayerFixture::PLAYER_PRIVATE, 'puzzle' => PuzzleFixture::PUZZLE_500_02],
        );

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        $ranks = $this->rankChips($browser, '/en/player-results/' . self::PLAYER);
        self::assertGreaterThan(5, count($ranks));

        $ranked = 0;

        foreach ($ranks as $puzzleId => $chip) {
            $leaderboard = $this->leaderboard($browser, $puzzleId);

            if ($chip === null) {
                self::assertArrayNotHasKey(self::PLAYER, $leaderboard['ranks'], 'No chip = not on the leaderboard: ' . $puzzleId);
                continue;
            }

            self::assertArrayHasKey(self::PLAYER, $leaderboard['ranks'], $puzzleId);
            self::assertSame($leaderboard['ranks'][self::PLAYER], $chip['rank'], 'Rank on ' . $puzzleId);
            self::assertSame($leaderboard['total'], $chip['total'], 'Of how many on ' . $puzzleId);
            $ranked++;
        }

        self::assertGreaterThan(5, $ranked);

        // The cases are really in it
        $first = $this->leaderboard($browser, PuzzleFixture::PUZZLE_500_01);
        self::assertSame($viewerId === PlayerFixture::PLAYER_WITH_FAVORITES, !isset($first['ranks'][PlayerFixture::PLAYER_ADMIN]), 'The blocked admin is gone for his blocker only');
        self::assertSame($viewerId === PlayerFixture::PLAYER_WITH_FAVORITES, isset($first['ranks'][PlayerFixture::PLAYER_PRIVATE]), 'The private player only for the friend she allows');
        self::assertArrayNotHasKey(PlayerFixture::PLAYER_PRIVATE, $this->leaderboard($browser, PuzzleFixture::PUZZLE_500_02)['ranks'], 'Her suspicious time ranks nowhere');
    }

    /**
     * Best rank: rank ascending, a larger field first among equal ranks, rows without a rank last
     */
    #[DataProvider('provideViewers')]
    public function testBestRankSortOrdersByTheShownRank(null|string $viewerId): void
    {
        $browser = self::createClient();

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        $chips = array_values($this->rankChips($browser, '/en/player-results/' . self::PLAYER . '?sort=rank'));
        $byDefault = $this->rankChips($browser, '/en/player-results/' . self::PLAYER);
        self::assertCount(count($byDefault), $chips, 'A sort keeps every row');

        $expected = $chips;
        usort($expected, static fn (null|array $a, null|array $b): int => ($a === null ? 1 : 0) - ($b === null ? 1 : 0)
            ?: ($a['rank'] ?? 0) <=> ($b['rank'] ?? 0)
            ?: ($b['total'] ?? 0) <=> ($a['total'] ?? 0));

        self::assertSame($expected, $chips);
        self::assertNotSame(array_values($byDefault), $chips, 'Newest is another order');
    }

    /**
     * Each By-puzzle Solo row's rank chip, by puzzle id (null = no chip), in the rows' order
     *
     * @return array<string, null|array{rank: int, total: int}>
     */
    private function rankChips(KernelBrowser $browser, string $url): array
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $chips = [];

        foreach ($crawler->filter('#player-results-rows tr[id^="result-"]') as $node) {
            $row = new Crawler($node);
            $puzzleId = basename((string) $row->filter('a.ps-puzzle-name')->attr('href'));
            $chip = $row->filter('.pr-chip-rank');

            if ($chip->count() === 0) {
                $chips[$puzzleId] = null;
                continue;
            }

            self::assertSame(1, preg_match('/^Rank (\d+) of (\d+)$/', trim($chip->text()), $match), $chip->text());
            $chips[$puzzleId] = ['rank' => (int) $match[1], 'total' => (int) $match[2]];
        }

        return $chips;
    }

    /**
     * The puzzle page's solo leaderboard as the same viewer sees it: rank by player id, and how many are on it
     *
     * @return array{ranks: array<string, int>, total: int}
     */
    private function leaderboard(KernelBrowser $browser, string $puzzleId): array
    {
        $crawler = $browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseIsSuccessful();

        $ranks = [];

        foreach ($crawler->filter('tr.lb-row[id^="leaderboard-row-"]') as $node) {
            $row = new Crawler($node);
            $ranks[substr((string) $row->attr('id'), strlen('leaderboard-row-'))] = (int) trim($row->filter('.lb-rank')->text());
        }

        self::assertSame(1, preg_match('/Solo \((\d+)\)/', $crawler->filter('.puzzle-category-types')->first()->text(), $match));

        return ['ranks' => $ranks, 'total' => (int) $match[1]];
    }
}
