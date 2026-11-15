<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DatePrecision;

use DateTimeImmutable;
use LogicException;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;
use Ramsey\Uuid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The precision on the result itself (docs/features/data-import/date-precision.md "The column", "Editing"): stored
 * only for a month or a year, on the first day of the period; an edit that picks another day makes the result exact,
 * an edit that leaves the date alone keeps what is known; a removed result comes back as it was.
 */
final class SolvingTimeDatePrecisionTest extends KernelTestCase
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

    public function testATypedResultKnowsItsDayAndAPeriodStandsOnItsFirstDay(): void
    {
        $time = $this->time('2021-03-01');

        self::assertNull($time->finishedAtPrecision);
        self::assertSame(DatePrecision::Day, $time->datePrecision());
        self::assertTrue($time->hasExactDay());

        $time->solveDayKnownOnlyTo(DatePrecision::Month);
        self::assertSame(DatePrecision::Month, $time->finishedAtPrecision);
        self::assertFalse($time->hasExactDay());

        // A day is the default - never stored
        $time->solveDayKnownOnlyTo(DatePrecision::Day);
        self::assertNull($time->finishedAtPrecision);

        // "2021" stands on 1 January, not on 1 March; "March 2021" not on the 15th
        $this->expectException(LogicException::class);
        $time->solveDayKnownOnlyTo(DatePrecision::Year);
    }

    public function testAMonthIsNeverStoredOnAnotherDayOfIt(): void
    {
        $this->expectException(LogicException::class);
        $this->time('2021-03-15')->solveDayKnownOnlyTo(DatePrecision::Month);
    }

    public function testAnEditThatLeavesTheDateAloneKeepsWhatIsKnownAndAnotherDayMakesItExact(): void
    {
        $time = $this->time('2021-03-01');
        $time->solveDayKnownOnlyTo(DatePrecision::Month);

        // The edit form sends the stored date back when the player did not touch it: only the time changed
        $this->edit($time, 4000, '2021-03-01');
        self::assertSame(DatePrecision::Month, $time->finishedAtPrecision);
        self::assertSame(4000, $time->secondsToSolve);

        // The player picked the day: they said it
        $this->edit($time, 4000, '2021-03-14');
        self::assertNull($time->finishedAtPrecision);
        self::assertSame('2021-03-14', $time->finishedAt?->format('Y-m-d'));

        // And it stays a day, whatever is saved next
        $this->edit($time, 4100, '2021-03-14');
        self::assertNull($time->finishedAtPrecision);
    }

    public function testARemovedResultComesBackWithItsPrecision(): void
    {
        $time = $this->time('2019-01-01');
        $time->solveDayKnownOnlyTo(DatePrecision::Year);

        $stored = RemovedResultSnapshot::of($time)->toArray();
        self::assertSame('year', $stored['finished_at_precision']);

        $restored = PuzzleSolvingTime::restore(RemovedResultSnapshot::fromArray($stored), $this->player, $this->puzzle, null, null);
        self::assertSame(DatePrecision::Year, $restored->finishedAtPrecision);

        // A snapshot written before the column existed is a day
        unset($stored['finished_at_precision']);
        self::assertSame(DatePrecision::Day, RemovedResultSnapshot::fromArray($stored)->finishedAtPrecision);
        self::assertNull(RemovedResultSnapshot::of($this->time('2021-03-15'))->toArray()['finished_at_precision']);
    }

    private function time(string $day): PuzzleSolvingTime
    {
        return new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: 3600,
            player: $this->player,
            puzzle: $this->puzzle,
            trackedAt: TestClock::now(),
            verified: false,
            team: null,
            finishedAt: new DateTimeImmutable($day . ' 00:00:00'),
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: false,
            unboxed: false,
        );
    }

    private function edit(PuzzleSolvingTime $time, int $seconds, string $day): void
    {
        $time->modify($seconds, null, null, new DateTimeImmutable($day . ' 00:00:00'), null, false, false, null, null);
    }
}
