<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The entity invariant of an unfinished result (docs/features/competitions-management/unfinished-results-plan.md):
 * pieces placed and a time to solve never go together, and only an unfinished result has a later total or a time
 * spent. Nothing here is flushed - the fixture player and puzzle are only read.
 */
final class PuzzleSolvingTimeUnfinishedTest extends KernelTestCase
{
    private Player $player;
    private Puzzle $puzzle;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($player);
        self::assertNotNull($puzzle);

        $this->player = $player;
        $this->puzzle = $puzzle;
    }

    public function testAnUnfinishedResultKeepsItsPiecesLaterTotalAndTimeSpent(): void
    {
        $time = $this->time(secondsToSolve: null, piecesPlaced: 479, finishedLaterSeconds: 6492, secondsSpent: 6492);

        self::assertNull($time->secondsToSolve);
        self::assertSame(479, $time->piecesPlaced);
        self::assertSame(6492, $time->finishedLaterSeconds);
        self::assertSame(6492, $time->secondsSpent);
    }

    public function testAnUnfinishedResultWithoutLaterTotalOrTimeSpentIsFine(): void
    {
        $time = $this->time(secondsToSolve: null, piecesPlaced: 479);

        self::assertSame(479, $time->piecesPlaced);
        self::assertNull($time->secondsSpent);
    }

    public function testAFinishedAndARelaxResultAreUntouched(): void
    {
        self::assertSame(3600, $this->time(secondsToSolve: 3600)->secondsToSolve);
        self::assertNull($this->time(secondsToSolve: null)->secondsToSolve);
    }

    public function testPiecesPlacedWithATimeToSolveIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->time(secondsToSolve: 3600, piecesPlaced: 479);
    }

    public function testALaterTotalWithoutPiecesPlacedIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->time(secondsToSolve: null, finishedLaterSeconds: 6492);
    }

    public function testATimeSpentWithoutPiecesPlacedIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->time(secondsToSolve: null, secondsSpent: 5400);
    }

    public function testATimeSpentNextToATimeToSolveIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->time(secondsToSolve: 3600, secondsSpent: 3600);
    }

    public function testModifyRefusesATimeToSolveOnAnUnfinishedResultAndChangesNothing(): void
    {
        $time = $this->time(secondsToSolve: null, piecesPlaced: 479, secondsSpent: 5400);
        $time->popEvents();

        try {
            $this->modify($time, seconds: 3600, comment: 'changed');
            self::fail('An unfinished result must not get a time to solve.');
        } catch (LogicException) {
        }

        self::assertNull($time->secondsToSolve);
        self::assertNull($time->comment);
        self::assertSame(479, $time->piecesPlaced);
        self::assertSame([], $time->popEvents());
    }

    public function testModifyWithoutATimeKeepsAnUnfinishedResult(): void
    {
        $time = $this->time(secondsToSolve: null, piecesPlaced: 479, secondsSpent: 5400);

        $this->modify($time, seconds: null, comment: 'ran out of time');

        self::assertSame('ran out of time', $time->comment);
        self::assertSame(479, $time->piecesPlaced);
        self::assertSame(5400, $time->secondsSpent);
    }

    public function testModifyOfAFinishedResultIsUntouched(): void
    {
        $time = $this->time(secondsToSolve: 3600);

        $this->modify($time, seconds: 3000, comment: null);

        self::assertSame(3000, $time->secondsToSolve);
    }

    public function testTheRemovedResultSnapshotCarriesTheTimeSpent(): void
    {
        $time = $this->time(secondsToSolve: null, piecesPlaced: 479, finishedLaterSeconds: 6492, secondsSpent: 6492);

        $snapshot = RemovedResultSnapshot::fromArray(RemovedResultSnapshot::of($time)->toArray());
        self::assertSame(6492, $snapshot->secondsSpent);

        $restored = PuzzleSolvingTime::restore($snapshot, $this->player, $this->puzzle, null, null);
        self::assertSame(479, $restored->piecesPlaced);
        self::assertSame(6492, $restored->finishedLaterSeconds);
        self::assertSame(6492, $restored->secondsSpent);
        self::assertNull($restored->secondsToSolve);
    }

    public function testASnapshotOlderThanTheTimeSpentReadsItAsNull(): void
    {
        $stored = RemovedResultSnapshot::of($this->time(secondsToSolve: 3600))->toArray();
        unset($stored['seconds_spent']);

        self::assertNull(RemovedResultSnapshot::fromArray($stored)->secondsSpent);
    }

    private function time(
        null|int $secondsToSolve,
        null|int $piecesPlaced = null,
        null|int $finishedLaterSeconds = null,
        null|int $secondsSpent = null,
    ): PuzzleSolvingTime {
        return new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: $secondsToSolve,
            player: $this->player,
            puzzle: $this->puzzle,
            trackedAt: new DateTimeImmutable('2026-11-14 18:00:00'),
            verified: false,
            team: null,
            finishedAt: new DateTimeImmutable('2026-11-14 18:00:00'),
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: false,
            unboxed: false,
            piecesPlaced: $piecesPlaced,
            finishedLaterSeconds: $finishedLaterSeconds,
            secondsSpent: $secondsSpent,
        );
    }

    private function modify(PuzzleSolvingTime $time, null|int $seconds, null|string $comment): void
    {
        $time->modify(
            seconds: $seconds,
            comment: $comment,
            puzzlersGroup: null,
            finishedAt: $time->finishedAt,
            finishedPuzzlePhoto: null,
            firstAttempt: false,
            unboxed: false,
            competition: null,
            puzzlingTeam: null,
        );
    }
}
