<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\TeamComposition;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The analytics studio's analytics JSON (analytics-studio.md §3.2, §4): history before the round only, this round's own
 * times out, opt-outs, private = unlinked, unfinished results never a solve or a time, the exact line-up's history.
 * Group B of the "Results Cup" (PUZZLE_5000, ten days before the frozen now, no stopwatch): Gina is
 * PLAYER_PRIVATE, Hugo PLAYER_REGULAR, Ivan unlinked.
 */
final class AnalyticsStudioAnalyticsControllerTest extends WebTestCase
{
    use AnalyticsStudioTesting;

    private const string HUGO = PlayerFixture::PLAYER_REGULAR;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testHistoryIsBeforeTheRoundOnlyAndUnfinishedIsNoSolve(): void
    {
        $browser = self::createClient();
        $this->seedHugosHistory();
        $this->clearAnalyticsStudioCache();

        $analytics = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/analytics?v=x');
        self::assertSame('max-age=30, private', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));

        $slot = $this->slotOf(F::ROUND_GROUP_B);
        self::assertSame([$slot], array_keys(self::digArray($analytics, 'puzzles')));
        $startsAt = $this->studioString('SELECT starts_at FROM competition_round WHERE id = :id', ['id' => F::ROUND_GROUP_B]);
        self::assertSame(self::ms($startsAt), self::dig($analytics, 'cutOffMs'), 'no stopwatch: the scheduled start');

        $hugo = self::dig($analytics, 'entrants', F::ENTRY_B_HUGO, 'members', 0);
        self::assertSame([
            'solves' => 3,
            'firstSeconds' => 20000,
            'bestSeconds' => 15000,
            'lastSeconds' => 15000,
            'firstTrySeconds' => 20000,
            'unfinished' => 1,
            'lastSolvedAtMs' => self::ms('2026-09-15 10:00:00'),
            'lastSolvedAtPrecision' => 'day',
            // Among every solver's best (7,000 private, 8,000 his own after the cut-off) his best before the round is 3rd
            'placeOnPuzzle' => 3,
            'asGroup' => null,
        ], self::dig($hugo, 'perPuzzle', $slot));

        // Gina (private) and Ivan (unlinked) are the same: nothing
        self::assertSame([], self::dig($analytics, 'entrants', F::ENTRY_B_GINA, 'members', 0));
        self::assertSame([], self::dig($analytics, 'entrants', F::ENTRY_B_IVAN, 'members', 0));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, (string) $browser->getResponse()->getContent());

        $puzzle = self::dig($analytics, 'puzzles', $slot);
        self::assertSame(2, self::dig($puzzle, 'solvers'), 'suspicious times are no solver');
        self::assertSame(7000, self::dig($puzzle, 'fastestSeconds'));
        self::assertSame(['count' => 1, 'medianSeconds' => 20000, 'fastestSeconds' => 20000], self::dig($puzzle, 'firstTries'));
        self::assertSame(['place' => 1, 'name' => null, 'country' => null, 'profile' => null, 'seconds' => 7000, 'inRound' => null], self::dig($puzzle, 'top', 0));
        self::assertSame([2, 8000, '/en/player-profile/' . self::HUGO, F::ENTRY_B_HUGO], [self::dig($puzzle, 'top', 1, 'place'), self::dig($puzzle, 'top', 1, 'seconds'), self::dig($puzzle, 'top', 1, 'profile'), self::dig($puzzle, 'top', 1, 'inRound')]);
        $solvers = 0;
        foreach (self::column(self::dig($puzzle, 'histogram'), 'count') as $count) {
            self::assertIsInt($count);
            $solvers += $count;
        }
        self::assertSame(2, $solvers);
    }

    public function testARecordHolderTurningPrivateIsNamelessOnTheNextAnswer(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        $this->seedStudioTime(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_5000, 6000, '2026-07-01 10:00:00');
        $this->clearAnalyticsStudioCache();
        $slot = $this->slotOf(F::ROUND_GROUP_B);
        $url = '/analytics-studio/' . F::ROUND_GROUP_B . '/analytics';

        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_WITH_FAVORITES, self::dig($this->studioJson($browser, $url), 'puzzles', $slot, 'top', 0, 'profile'));
        self::assertIsString(self::dig($this->studioJson($browser, $url), 'puzzles', $slot, 'top', 0, 'name'));

        // Not an entrant: the cached analytics stay - only who the record holder is is read again
        $database->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]);
        $content = json_encode($this->studioJson($browser, $url), JSON_THROW_ON_ERROR);
        self::assertSame(['place' => 1, 'name' => null, 'country' => null, 'profile' => null, 'seconds' => 6000, 'inRound' => null], self::dig(json_decode($content, true, flags: JSON_THROW_ON_ERROR), 'puzzles', $slot, 'top', 0));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_FAVORITES, $content);
    }

    public function testNumbersAsOfNowWithTheOptOutsHonoured(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        $database->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, 1.5, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'player' => self::HUGO],
        );
        foreach ([500 => 4, 5000 => 3] as $pieces => $tier) {
            $database->executeStatement(
                'INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at) VALUES (:id, :player, :pieces, 0.5, :tier, 50, :confidence, 10, ' . TestClock::SQL_NOW . ')',
                ['id' => Uuid::uuid7()->toString(), 'player' => self::HUGO, 'pieces' => $pieces, 'tier' => $tier, 'confidence' => 'high'],
            );
        }
        $this->clearAnalyticsStudioCache();
        $slot = $this->slotOf(F::ROUND_GROUP_B);
        $url = '/analytics-studio/' . F::ROUND_GROUP_B . '/analytics';

        $hugo = self::dig($this->studioJson($browser, $url), 'entrants', F::ENTRY_B_HUGO, 'members', 0);
        self::assertSame(1500, self::dig($hugo, 'rating'));
        self::assertIsInt(self::dig($hugo, 'ratingPlace'));
        self::assertSame([$slot => 'proficient'], self::dig($hugo, 'skill'), 'the tier at the puzzle\'s piece count, as the API token');
        self::assertFalse(self::dig($hugo, 'rankingOptedOut'));

        $database->executeStatement('UPDATE player SET ranking_opted_out = true, time_predictions_opted_out = true WHERE id = :id', ['id' => self::HUGO]);
        $hugo = self::dig($this->studioJson($browser, $url), 'entrants', F::ENTRY_B_HUGO, 'members', 0);
        self::assertNull(self::dig($hugo, 'rating'));
        self::assertNull(self::dig($hugo, 'ratingPlace'));
        self::assertNull(self::dig($hugo, 'skill'));
        self::assertTrue(self::dig($hugo, 'rankingOptedOut'));
        self::assertArrayHasKey('perPuzzle', self::digArray($hugo), 'his public history stays');
    }

    public function testSimilarPuzzlesLeaveOutTheRoundsOwnAndEmbargoedOnes(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        // PUZZLE_6000 becomes a second 5000-piece puzzle of the same brand
        $database->executeStatement(
            'UPDATE puzzle SET pieces_count = 5000, manufacturer_id = (SELECT manufacturer_id FROM puzzle WHERE id = :same) WHERE id = :id',
            ['id' => PuzzleFixture::PUZZLE_6000, 'same' => PuzzleFixture::PUZZLE_5000],
        );
        $this->seedStudioTime(self::HUGO, PuzzleFixture::PUZZLE_6000, 18000, '2026-09-10 10:00:00');
        $this->seedStudioTime(self::HUGO, PuzzleFixture::PUZZLE_6000, 12000, '2026-10-12 10:00:00');
        $this->seedStudioTime(self::HUGO, PuzzleFixture::PUZZLE_5000, 15000, '2026-09-15 10:00:00');
        $this->clearAnalyticsStudioCache();
        $slot = $this->slotOf(F::ROUND_GROUP_B);
        $url = '/analytics-studio/' . F::ROUND_GROUP_B . '/analytics';

        $similar = self::dig($this->studioJson($browser, $url), 'entrants', F::ENTRY_B_HUGO, 'members', 0, 'similar', $slot);
        self::assertSame(['sizeCount' => 1, 'sizeMedian' => 18000, 'sizeBest' => 18000, 'brandCount' => 1, 'brandMedian' => 18000, 'brandBest' => 18000], $similar, 'the round puzzle itself and the solve after the cut-off are out');

        $database->executeStatement("UPDATE puzzle SET hide_until = TIMESTAMP '2027-01-01 00:00:00' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_6000]);
        $this->clearAnalyticsStudioCache();
        $similar = self::dig($this->studioJson($browser, $url), 'entrants', F::ENTRY_B_HUGO, 'members', 0, 'similar', $slot);
        self::assertSame(0, self::dig($similar, 'sizeCount'), 'a puzzle under the embargo is never similar history');
    }

    public function testAPairsLineUpHasItsOwnHistoryWhenEveryMemberIsPublic(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        // Ben (the other half of the Puzzle Sharks with Anna = PLAYER_ADMIN) is PLAYER_WITH_FAVORITES
        $database->executeStatement('UPDATE competition_participant SET player_id = :player WHERE id = :id', ['player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'id' => F::PARTICIPANT_BEN]);
        $teamId = $this->seedPair([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_FAVORITES]);
        $this->seedPairTime($teamId, PuzzleFixture::PUZZLE_2000, 9000, '2026-09-01 10:00:00', PlayerFixture::PLAYER_ADMIN);
        $this->seedPairTime($teamId, PuzzleFixture::PUZZLE_2000, 6000, '2026-10-10 10:00:00', PlayerFixture::PLAYER_ADMIN);
        $this->clearAnalyticsStudioCache();
        $slot = $this->slotOf(F::ROUND_PAIRS);

        $analytics = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_PAIRS . '/analytics');
        $sharks = self::dig($analytics, 'entrants', F::TEAM_SHARKS);
        self::assertSame(1, self::dig($sharks, 'lineup', 'perPuzzle', $slot, 'solves'));
        self::assertSame(9000, self::dig($sharks, 'lineup', 'perPuzzle', $slot, 'bestSeconds'));
        self::assertSame(['solves' => 1, 'bestSeconds' => 9000], self::dig($sharks, 'members', 0, 'perPuzzle', $slot, 'asGroup'), "a member's pair history");
        self::assertSame(0, self::dig($sharks, 'members', 0, 'perPuzzle', $slot, 'solves'), 'no solo history');

        // Edge Hunters: Gina is private - no line-up history, and nothing of her
        self::assertNull(self::dig($analytics, 'entrants', F::TEAM_EDGES, 'lineup'));
        self::assertSame([], self::dig($analytics, 'entrants', F::TEAM_EDGES, 'members', 0));
        // The group distribution of the pairs
        self::assertGreaterThanOrEqual(1, self::dig($analytics, 'puzzles', $slot, 'group', 'solvers'));
    }

    public function testBeforeAnyRevealNothingIsKeyedByAPuzzle(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        $database->executeStatement(
            "UPDATE competition_round_puzzle SET hide_until_round_starts = true, reveal_mode = 'manual', reveal_at = NULL WHERE round_id = :round",
            ['round' => F::ROUND_GROUP_B],
        );
        $this->seedHugosHistory();
        $this->clearAnalyticsStudioCache();

        $analytics = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/analytics');
        self::assertSame([], self::dig($analytics, 'puzzles'));
        $hugo = self::digArray($analytics, 'entrants', F::ENTRY_B_HUGO, 'members', 0);
        self::assertArrayNotHasKey('perPuzzle', $hugo);
        self::assertArrayNotHasKey('similar', $hugo);
        self::assertSame([500], array_keys(self::digArray($hugo, 'skill')), 'skill at 500 only - no piece count implied');
        self::assertFalse(self::dig($hugo, 'rankingOptedOut'));
    }

    private function seedHugosHistory(): void
    {
        $puzzle = PuzzleFixture::PUZZLE_5000;
        $this->seedStudioTime(self::HUGO, $puzzle, 20000, '2026-08-01 10:00:00', firstAttempt: true);
        $this->seedStudioTime(self::HUGO, $puzzle, 16000, '2026-09-01 00:00:00', precision: 'month');
        $this->seedStudioTime(self::HUGO, $puzzle, 15000, '2026-09-15 10:00:00');
        $this->seedStudioTime(self::HUGO, $puzzle, null, '2026-09-20 10:00:00', piecesPlaced: 3000);
        $this->seedStudioTime(self::HUGO, $puzzle, 9000, '2026-09-25 10:00:00', suspicious: true);
        // A month known only to its month that ends after the cut-off, a time logged in this round, one after the cut-off
        $this->seedStudioTime(self::HUGO, $puzzle, 10000, '2026-10-01 00:00:00', precision: 'month');
        $this->seedStudioTime(self::HUGO, $puzzle, 14000, '2026-09-30 10:00:00', roundId: F::ROUND_GROUP_B);
        $this->seedStudioTime(self::HUGO, $puzzle, 8000, '2026-10-10 10:00:00');
        // A private solver
        $this->seedStudioTime(PlayerFixture::PLAYER_PRIVATE, $puzzle, 7000, '2026-07-01 10:00:00');
    }

    private function slotOf(string $roundId): string
    {
        return strtolower($this->studioString('SELECT id FROM competition_round_puzzle WHERE round_id = :round', ['round' => $roundId]));
    }

    /**
     * @param list<string> $playerIds
     */
    private function seedPair(array $playerIds): string
    {
        $database = $this->studioDatabase();
        $teamId = Uuid::uuid7()->toString();
        $database->executeStatement(
            'INSERT INTO puzzling_team (id, composition_key, size, created_at) VALUES (:id, :key, :size, ' . TestClock::SQL_NOW . ')',
            ['id' => $teamId, 'key' => TeamComposition::keyFromMemberKeys(array_map('strtolower', $playerIds)), 'size' => count($playerIds)],
        );
        foreach ($playerIds as $position => $playerId) {
            $database->executeStatement(
                'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, position) VALUES (:id, :team, :key, :player, :position)',
                ['id' => Uuid::uuid7()->toString(), 'team' => $teamId, 'key' => strtolower($playerId), 'player' => $playerId, 'position' => $position],
            );
        }

        return $teamId;
    }

    private function seedPairTime(string $teamId, string $puzzleId, int $seconds, string $finishedAt, null|string $trackerId): void
    {
        $this->studioDatabase()->executeStatement(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, suspicious, puzzling_team_id, puzzling_type, puzzlers_count)
             VALUES (:id, :player, :puzzle, :seconds, :at, :at, true, false, false, :team, 'duo', 2)",
            ['id' => Uuid::uuid7()->toString(), 'player' => $trackerId, 'puzzle' => $puzzleId, 'seconds' => $seconds, 'at' => $finishedAt, 'team' => $teamId],
        );
    }

    private static function ms(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp() * 1000;
    }
}
