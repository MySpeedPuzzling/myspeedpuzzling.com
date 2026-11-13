<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use SpeedPuzzling\Web\Value\TeamComposition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A team result imported from an organizer's results sheet (docs/features/event-results-import/README.md): a
 * solving time of nobody yet - no tracker, anonymous slots - that belongs to the event.
 */
final class PuzzleSolvingTimeEventImportTest extends KernelTestCase
{
    public function testAnImportedTeamResultHasNoTrackerAndOneAnonymousSlotPerPerson(): void
    {
        $time = $this->importedTime(['a:1', 'a:2', 'a:3', 'a:4']);

        self::assertNull($time->player);
        self::assertSame(1199, $time->secondsToSolve);
        self::assertSame(4, $time->puzzlersCount);
        self::assertSame(PuzzlingType::Team, $time->puzzlingType);
        self::assertSame(SolvingTimeSource::EventImport, $time->createdVia);
        self::assertSame([], $time->memberPlayerIds());
        self::assertTrue($time->isImportedFromEvent());
    }

    public function testAnImportedPairIsADuo(): void
    {
        self::assertSame(PuzzlingType::Duo, $this->importedTime(['a:1', 'a:2'])->puzzlingType);
    }

    public function testFirstTryIsNeverKnownOnImport(): void
    {
        self::assertFalse($this->importedTime(['a:1', 'a:2'])->firstAttempt);
    }

    public function testItIsSolvedAndTrackedOnTheEventDayNotTheImportDay(): void
    {
        $time = $this->importedTime(['a:1', 'a:2']);

        self::assertEquals(new DateTimeImmutable('2025-10-08 19:00:00'), $time->finishedAt);
        self::assertEquals(new DateTimeImmutable('2025-10-08 19:00:00'), $time->trackedAt);
    }

    public function testNobodyMayModifyAnImportedResult(): void
    {
        $time = $this->importedTime(['a:1', 'a:2']);
        $player = self::getContainer()->get(EntityManagerInterface::class)->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);

        // Only the organizer's sheet changes it - claimers release their spot instead (README "Gates")
        self::assertFalse($time->canBeModifiedBy($player));
    }

    /**
     * @param non-empty-list<string> $slots
     */
    private function importedTime(array $slots): PuzzleSolvingTime
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_500_01);
        $competition = $entityManager->find(Competition::class, CompetitionFixture::COMPETITION_WJPC_2024);
        $round = $entityManager->find(CompetitionRound::class, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertNotNull($puzzle);
        self::assertNotNull($competition);
        self::assertNotNull($round);

        $group = PuzzlersGroup::anonymous($slots);
        $puzzlingTeam = new PuzzlingTeam(
            Uuid::uuid7(),
            TeamComposition::fromGroup($group)->key,
            count($slots),
            new DateTimeImmutable('2025-10-09 08:00:00'),
            'Puzzle Pals',
        );

        return PuzzleSolvingTime::importedFromEvent(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            competition: $competition,
            competitionRound: $round,
            anonymousGroup: $group,
            puzzlingTeam: $puzzlingTeam,
            secondsToSolve: 1199,
            solvedAt: new DateTimeImmutable('2025-10-08 19:00:00'),
        );
    }
}
