<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Component\PuzzleTimes;
use SpeedPuzzling\Web\Exceptions\PuzzleResultNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleResultDetail;
use SpeedPuzzling\Web\Results\PuzzleResultAttempt;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPuzzleResultDetailTest extends KernelTestCase
{
    private GetPuzzleResultDetail $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPuzzleResultDetail::class);
    }

    public function testSoloAttemptsOfThatPlayerNewestFirstWithComparisons(): void
    {
        // PLAYER_REGULAR on PUZZLE_500_02: 2200 (20 days ago), 1900 (15), 1700 (10)
        $result = $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_06, null);

        self::assertSame('solo', $result->puzzlingType);
        self::assertFalse($result->isGroup());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $result->player->playerId);
        self::assertSame(PuzzleSolvingTimeFixture::TIME_06, $result->focusTimeId);
        self::assertSame(
            [PuzzleSolvingTimeFixture::TIME_08, PuzzleSolvingTimeFixture::TIME_07, PuzzleSolvingTimeFixture::TIME_06],
            array_map(static fn (PuzzleResultAttempt $attempt): string => $attempt->timeId, $result->attempts),
        );

        self::assertNotNull($result->bestAttempt);
        self::assertSame(1700, $result->bestAttempt->time);

        [$newest, $middle, $oldest] = $result->attempts;
        self::assertTrue($newest->isBest);
        self::assertSame(-200, $newest->deltaToPrevious);
        self::assertNull($newest->gapToBest);
        self::assertSame(-300, $middle->deltaToPrevious);
        self::assertSame(200, $middle->gapToBest);
        self::assertNull($oldest->deltaToPrevious);
        self::assertSame(500, $oldest->gapToBest);
    }

    public function testSoloStandingForAGuest(): void
    {
        // Leaderboard of PUZZLE_500_02 for a guest: 1350, 1700 (PLAYER_REGULAR), 2000, 2100 - the private player is left out
        $standing = $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_08, null)->standing;

        self::assertNotNull($standing);
        self::assertSame(2, $standing->rank);
        self::assertSame(4, $standing->total);
        self::assertSame(1350, $standing->leaderTime);
        self::assertSame(350, $standing->gapToLeader());
        // The next faster time is the leader's: the leader gap says it
        self::assertNull($standing->gapToNextFaster());
    }

    public function testStandingWithAFasterTimeBetweenTheSubjectAndTheLeader(): void
    {
        $standing = $this->query->standing(PuzzleFixture::PUZZLE_500_02, 'solo', PlayerFixture::PLAYER_WITH_STRIPE, null);

        self::assertNotNull($standing);
        self::assertSame(3, $standing->rank);
        self::assertSame(4, $standing->total);
        self::assertSame(650, $standing->gapToLeader());
        self::assertSame(300, $standing->gapToNextFaster());
    }

    public function testPrivatePlayerCountsOnlyForThemselves(): void
    {
        // PLAYER_PRIVATE's own best (1650) stands between the leader and PLAYER_REGULAR for her only
        $standing = $this->query->standing(PuzzleFixture::PUZZLE_500_02, 'solo', PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE);

        self::assertNotNull($standing);
        self::assertSame(3, $standing->rank);
        self::assertSame(5, $standing->total);
        self::assertSame(50, $standing->gapToNextFaster());
    }

    public function testRelaxSolvesAreListedWithoutAffectingTheBest(): void
    {
        // PLAYER_REGULAR on PUZZLE_1000_02: 4500 (event), 3950 and a relax solve without a date
        $result = $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT, null);

        self::assertCount(3, $result->attempts);
        self::assertCount(2, $result->timedAttempts());
        self::assertNotNull($result->bestAttempt);
        self::assertSame(3950, $result->bestAttempt->time);
        self::assertNotNull($result->standing);

        $relax = array_values(array_filter($result->attempts, static fn (PuzzleResultAttempt $attempt): bool => $attempt->time === null));
        self::assertCount(1, $relax);
        self::assertNull($relax[0]->deltaToPrevious);
        self::assertNull($relax[0]->gapToBest);
    }

    public function testSuspiciousTimesAreListedForEverybodyButNeverTheBest(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_06],
        );

        foreach ([null, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR] as $viewerId) {
            $result = $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_08, $viewerId);

            self::assertCount(3, $result->attempts);
            self::assertTrue($result->attempts[2]->suspicious);
            self::assertNotNull($result->bestAttempt);
            self::assertFalse($result->bestAttempt->suspicious);
        }
    }

    public function testPairAttemptsAndStanding(): void
    {
        $result = $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_12, null);

        self::assertTrue($result->isGroup());
        self::assertSame('duo', $result->puzzlingType);
        self::assertNotNull($result->teamId);
        self::assertSame(2, $result->membersCount());
        // TIME_41 is the same pair on another puzzle
        self::assertCount(1, $result->attempts);
        self::assertTrue($result->attempts[0]->isEditableBy(PlayerFixture::PLAYER_PRIVATE), 'Every registered member may edit a pair time');
        self::assertFalse($result->attempts[0]->isEditableBy(PlayerFixture::PLAYER_ADMIN));

        self::assertNotNull($result->standing);
        self::assertSame(1, $result->standing->rank);
        self::assertSame(1, $result->standing->total);
        self::assertNull($result->standing->gapToLeader());
    }

    public function testPairOfOnlyPrivateMembersIsNotFoundExceptForTheMembers(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET is_private = true WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertNotNull($this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_REGULAR)->standing);

        $this->expectException(PuzzleResultNotFound::class);
        $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_ADMIN);
    }

    public function testPrivateSoloPlayerIsNotFoundForOthers(): void
    {
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_02, PlayerFixture::PLAYER_PRIVATE)->player->playerId);

        $this->expectException(PuzzleResultNotFound::class);
        $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_02, null);
    }

    #[DataProvider('provideUnknownIds')]
    public function testUnknownTimeIsNotFound(string $timeId): void
    {
        $this->expectException(PuzzleResultNotFound::class);
        $this->query->byTimeId($timeId, null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnknownIds(): iterable
    {
        yield 'unknown' => ['019a0000-0000-7000-8000-000000000000'];
        yield 'not a uuid' => ['nope'];
    }

    /**
     * The standing must say exactly what the leaderboard row says - rank, leader gap, next faster gap - for every
     * row of the unfiltered solo and pair boards.
     */
    #[DataProvider('provideLeaderboards')]
    public function testStandingEqualsTheLeaderboard(string $puzzleId, string $category, string $puzzlingType): void
    {
        $component = self::getContainer()->get(PuzzleTimes::class);
        $component->puzzleId = $puzzleId;
        $component->category = $category;
        $component->populate();
        self::assertNotEmpty($component->times);

        foreach ($component->times as $rowKey => $grouped) {
            $best = $grouped[0];
            $subjectId = $best instanceof PuzzleSolver ? $best->playerId : $best->teamId;
            self::assertNotNull($subjectId);

            $standing = $this->query->standing($puzzleId, $puzzlingType, $subjectId, null);
            self::assertNotNull($standing, "Row {$rowKey}");
            self::assertSame($component->ranks[$rowKey], $standing->rank, "Rank of {$rowKey}");
            self::assertSame(count($component->times), $standing->total);
            self::assertSame($component->leaderTime, $standing->leaderTime);
            self::assertSame($component->medianTime, $standing->medianTime, 'Median of the leaderboard');
            self::assertSame($component->gapsToFaster[$rowKey] ?? null, $standing->gapToNextFaster(), "Next faster gap of {$rowKey}");
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideLeaderboards(): iterable
    {
        yield 'solo 500_02' => [PuzzleFixture::PUZZLE_500_02, 'solo', 'solo'];
        yield 'solo 500_01' => [PuzzleFixture::PUZZLE_500_01, 'solo', 'solo'];
        yield 'solo 1000_01' => [PuzzleFixture::PUZZLE_1000_01, 'solo', 'solo'];
        yield 'pairs 1000_01' => [PuzzleFixture::PUZZLE_1000_01, 'duo', 'duo'];
    }
}
