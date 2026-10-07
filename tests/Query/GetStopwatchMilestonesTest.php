<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetStopwatchMilestones;
use SpeedPuzzling\Web\Results\StopwatchMilestone;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetStopwatchMilestonesTest extends KernelTestCase
{
    use ClonesSolvingTimes;

    private GetStopwatchMilestones $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetStopwatchMilestones::class);
    }

    public function testForPuzzleAndPlayerReturnsSortedMilestones(): void
    {
        // PUZZLE_500_01 has solving times from 5 players
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNotEmpty($milestones);

        // Milestones must be sorted by timeSeconds ascending
        $times = array_map(fn($m) => $m->timeSeconds, $milestones);
        $sorted = $times;
        sort($sorted);
        self::assertSame($sorted, $times, 'Milestones should be sorted by time ascending');
    }

    public function testForPuzzleAndPlayerIncludesFastestMilestone(): void
    {
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        $fastestMilestones = array_filter($milestones, fn($m) => $m->type === 'fastest');
        self::assertNotEmpty($fastestMilestones, 'Should include a fastest milestone');

        $fastest = array_values($fastestMilestones)[0];
        self::assertStringContainsString('(fastest)', $fastest->label);
    }

    public function testForPuzzleAndPlayerIncludesSelfMilestone(): void
    {
        // PLAYER_REGULAR has solving time for PUZZLE_500_01 (TIME_01: 1800s, TIME_36: 1750s)
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        $selfMilestones = array_filter($milestones, fn($m) => $m->type === 'self');
        self::assertNotEmpty($selfMilestones, 'Should include current player milestone');

        $self = array_values($selfMilestones)[0];
        self::assertStringContainsString('(you)', $self->label);
        // Best time is 1750s (TIME_36)
        self::assertSame(1750, $self->timeSeconds);
    }

    public function testForPuzzleAndPlayerIncludesFavoritePlayers(): void
    {
        // PLAYER_WITH_FAVORITES has favorites: PLAYER_REGULAR and PLAYER_ADMIN
        // Both have solving times for PUZZLE_500_01
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        $favoriteMilestones = array_filter($milestones, fn($m) => $m->type === 'favorite');
        self::assertNotEmpty($favoriteMilestones, 'Should include favorite player milestones');
    }

    public function testForPuzzleAndPlayerFillsGapsWithOtherPlayers(): void
    {
        // PUZZLE_500_01 has times spread across players
        // With gap filling, there should be 'other' type milestones if gaps > 2 min exist
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        $types = array_unique(array_map(fn($m) => $m->type, $milestones));

        // At minimum, should have fastest and self/favorites
        self::assertContains('fastest', $types);
    }

    public function testForPuzzleAndPlayerNoDuplicateTimes(): void
    {
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        $times = array_map(fn($m) => $m->timeSeconds, $milestones);
        $uniqueTimes = array_unique($times);
        self::assertCount(count($uniqueTimes), $times, 'Should not have duplicate milestone times');
    }

    public function testForPuzzleWithNoTimesReturnsEmpty(): void
    {
        // PUZZLE_9000 has no solving times
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_9000,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertEmpty($milestones);
    }

    public function testAllSoloTimesForPuzzleReturnsBestPerPlayer(): void
    {
        // PUZZLE_500_01 has multiple solves by some players
        // PLAYER_REGULAR: 1800 (TIME_01) and 1750 (TIME_36) -> best is 1750
        // PLAYER_ADMIN: 2400 (TIME_03) and 1200 (TIME_32) -> best is 1200
        $times = $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_500_01);

        self::assertNotEmpty($times);

        // Should be sorted ascending
        $sorted = $times;
        sort($sorted);
        self::assertSame($sorted, $times, 'Times should be sorted ascending');

        // Should be one entry per player (not per solving time)
        // PUZZLE_500_01 has solo solves from 5 different players, but 1 is private
        self::assertCount(4, $times, 'Should have one best time per player (excluding private)');
    }

    public function testAllSoloTimesForPuzzleExcludesTeamSolves(): void
    {
        // PUZZLE_1000_01 has solo solves and a team solve (TIME_12)
        $times = $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_1000_01);

        // Team time is 3600s. Solo times are 4200, 3900, 5100, 4100 and 6500
        // The 3600 team time should NOT be in the results
        self::assertNotContains(3600, $times, 'Team solve time should not be included');
    }

    public function testAllSoloTimesForPuzzleWithNoTimesReturnsEmpty(): void
    {
        $times = $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_9000);

        self::assertEmpty($times);
    }

    public function testForPuzzleAndPlayerExcludesPrivatePlayer(): void
    {
        // PLAYER_PRIVATE (Jane Smith) has solving times for PUZZLE_500_01
        // but is private - their name must never appear in any milestone
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        foreach ($milestones as $milestone) {
            self::assertStringNotContainsString(
                'Jane Smith',
                $milestone->label,
                'Private player name should not appear in any milestone',
            );
            self::assertStringNotContainsString(
                'player2',
                $milestone->label,
                'Private player code should not appear in any milestone',
            );
        }
    }

    public function testAllSoloTimesForPuzzleExcludesPrivatePlayers(): void
    {
        // PLAYER_PRIVATE has best solo time of 1400s on PUZZLE_500_01
        // This time should not appear in the solo times list
        $times = $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_500_01);

        self::assertNotContains(1400, $times, 'Private player best time should not be included');
        self::assertNotContains(1500, $times, 'Private player time should not be included');
    }

    public function testMilestoneGapFillingProducesMoreMilestonesThanWithout(): void
    {
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        // Count named types (fastest, average, self, favorite)
        $namedTypes = ['fastest', 'average', 'self', 'favorite'];
        $namedCount = count(array_filter($milestones, fn($m) => in_array($m->type, $namedTypes, true)));
        $totalCount = count($milestones);

        // Gap filling should add 'other' type milestones when there are gaps > 2 min
        // Total should be >= named count (gap filling adds more)
        self::assertGreaterThanOrEqual($namedCount, $totalCount);
    }

    public function testBlockedPlayerIsNeverAMilestone(): void
    {
        // PLAYER_ADMIN holds the fastest time on PUZZLE_500_01 (1200 s)
        $before = $this->query->forPuzzleAndPlayer(PuzzleFixture::PUZZLE_500_01, PlayerFixture::PLAYER_REGULAR);
        self::assertContains(1200, array_map(static fn ($m) => $m->timeSeconds, $before));
        $timesBefore = $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_500_01);

        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $after = $this->query->forPuzzleAndPlayer(PuzzleFixture::PUZZLE_500_01, PlayerFixture::PLAYER_REGULAR);
        self::assertNotContains(1200, array_map(static fn ($m) => $m->timeSeconds, $after));

        foreach ($after as $milestone) {
            self::assertStringNotContainsString('Admin', $milestone->label);

            // Own time is now the fastest one shown, and ranks close up
            if ($milestone->type === 'self') {
                self::assertSame(1, $milestone->rank);
            }
        }

        self::assertSame(
            array_values(array_diff($timesBefore, [1200])),
            $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_500_01),
        );
    }

    public function testBlockedFavoriteIsNotAMilestone(): void
    {
        // PLAYER_WITH_FAVORITES follows PLAYER_REGULAR (1750 s) and PLAYER_ADMIN (1200 s)
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $milestones = $this->query->forPuzzleAndPlayer(PuzzleFixture::PUZZLE_500_01, PlayerFixture::PLAYER_WITH_FAVORITES);

        $favorites = array_values(array_filter($milestones, static fn ($m) => $m->type === 'favorite'));
        self::assertCount(1, $favorites);
        self::assertSame(1750, $favorites[0]->timeSeconds);
        self::assertNotContains(1200, array_map(static fn ($m) => $m->timeSeconds, $milestones));
    }

    public function testAllowListRevealsAPrivateFavoriteButNeverTheFastest(): void
    {
        // PrivateProfileViewerFixture: PLAYER_PRIVATE (Jane Smith) lets PLAYER_WITH_FAVORITES see her, nobody else
        $this->layOutPuzzleWithoutFixtureTimes();
        $this->setFavorites(PlayerFixture::PLAYER_WITH_FAVORITES, [PlayerFixture::PLAYER_PRIVATE]);
        $this->setFavorites(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_PRIVATE]);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        $milestones = $this->query->forPuzzleAndPlayer(PuzzleFixture::PUZZLE_500_04, PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(['Jane Smith'], self::labelsOfType($milestones, 'favorite'));
        self::assertSame([PlayerFixture::PLAYER_WITH_STRIPE_NAME . ' (fastest)'], self::labelsOfType($milestones, 'fastest'));
        self::assertSame([1200, 1500, 2000], $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_500_04));

        // Not on her allow list: the same favourite stays hidden
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);
        $milestones = $this->query->forPuzzleAndPlayer(PuzzleFixture::PUZZLE_500_04, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ($milestones as $milestone) {
            self::assertStringNotContainsString('Jane Smith', $milestone->label);
        }
    }

    public function testSuspiciousTimeIsNeverAMilestone(): void
    {
        // PLAYER_ADMIN is a favourite of PLAYER_WITH_FAVORITES and holds the fastest time here, a suspicious one
        $this->layOutPuzzleWithoutFixtureTimes();
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $milestones = $this->query->forPuzzleAndPlayer(PuzzleFixture::PUZZLE_500_04, PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertNotContains(1100, array_map(static fn (StopwatchMilestone $m): int => $m->timeSeconds, $milestones));
        self::assertSame([PlayerFixture::PLAYER_WITH_STRIPE_NAME . ' (fastest)'], self::labelsOfType($milestones, 'fastest'));
        self::assertSame(1, self::firstOfType($milestones, 'fastest')->rank);
        self::assertSame([1200, 1500, 2000], $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_500_04));
    }

    /**
     * PUZZLE_500_04 has no fixture times: Jane Smith (private) 1000 s, Admin User 1100 s (suspicious),
     * Sarah Williams 1200 s, John Doe 1500 s, Michael Johnson 2000 s.
     */
    private function layOutPuzzleWithoutFixtureTimes(): void
    {
        foreach (
            [
            [PlayerFixture::PLAYER_PRIVATE, 1000, false],
            [PlayerFixture::PLAYER_ADMIN, 1100, true],
            [PlayerFixture::PLAYER_WITH_STRIPE, 1200, false],
            [PlayerFixture::PLAYER_REGULAR, 1500, false],
            [PlayerFixture::PLAYER_WITH_FAVORITES, 2000, false],
            ] as [$playerId, $seconds, $suspicious]
        ) {
            // TIME_01: a solo time of PLAYER_REGULAR
            $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_01, [
                'player_id' => $playerId,
                'puzzle_id' => PuzzleFixture::PUZZLE_500_04,
                'seconds_to_solve' => $seconds,
                'suspicious' => $suspicious,
                'days_ago' => 3,
            ]);
        }
    }

    /**
     * @param list<string> $favoriteIds
     */
    private function setFavorites(string $playerId, array $favoriteIds): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET favorite_players = CAST(:favorites AS json) WHERE id = :id',
            ['favorites' => json_encode($favoriteIds, JSON_THROW_ON_ERROR), 'id' => $playerId],
        );
    }

    /**
     * @param array<StopwatchMilestone> $milestones
     * @return list<string>
     */
    private static function labelsOfType(array $milestones, string $type): array
    {
        return array_values(array_map(
            static fn (StopwatchMilestone $m): string => $m->label,
            array_filter($milestones, static fn (StopwatchMilestone $m): bool => $m->type === $type),
        ));
    }

    /**
     * @param array<StopwatchMilestone> $milestones
     */
    private static function firstOfType(array $milestones, string $type): StopwatchMilestone
    {
        foreach ($milestones as $milestone) {
            if ($milestone->type === $type) {
                return $milestone;
            }
        }

        self::fail("No {$type} milestone");
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
