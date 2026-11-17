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

    public function testAnImportedSinglePersonIsASoloResultWithoutItsPlayer(): void
    {
        $time = $this->importedSoloTime();

        // Shape A (solo-and-relays.md §1): an ordinary solo row without its player - no team, no pair/team
        self::assertNull($time->player);
        self::assertNull($time->team);
        self::assertNull($time->puzzlingTeam);
        self::assertSame(1, $time->puzzlersCount);
        self::assertSame(PuzzlingType::Solo, $time->puzzlingType);
        self::assertSame(SolvingTimeSource::EventImport, $time->createdVia);
        self::assertFalse($time->firstAttempt);
        self::assertFalse($time->isClaimedByAnybody());
        self::assertTrue($time->keepsItsRound());
    }

    public function testAClaimedSoloIsTheClaimersAndAReleaseMakesItNobodysAgain(): void
    {
        $time = $this->importedSoloTime();
        $player = self::getContainer()->get(EntityManagerInterface::class)->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);

        $time->slotClaimedBy($player, firstTry: true);

        self::assertTrue($time->isTrackedBy($player));
        self::assertTrue($time->isClaimedByAnybody());
        self::assertTrue($time->firstAttempt);
        self::assertSame(PuzzlingType::Solo, $time->puzzlingType);
        // Still the event's: released, never edited
        self::assertFalse($time->canBeModifiedBy($player));
        self::assertFalse($time->canBeDeletedBy($player));

        $time->slotReleasedBy($player, null);

        self::assertNull($time->player);
        self::assertFalse($time->firstAttempt);
        self::assertFalse($time->isClaimedByAnybody());
    }

    public function testATeamTheSheetTurnsIntoOnePersonBecomesASoloResultAndBack(): void
    {
        $time = $this->importedTime(['a:1', 'a:2', 'a:3']);

        $time->becomeAnonymousSolo();

        self::assertNull($time->team);
        self::assertNull($time->puzzlingTeam);
        self::assertSame(1, $time->puzzlersCount);
        self::assertSame(PuzzlingType::Solo, $time->puzzlingType);

        $group = PuzzlersGroup::anonymous(['a:5', 'a:6']);
        $time->replaceAnonymousGroup($group, new PuzzlingTeam(Uuid::uuid7(), TeamComposition::fromGroup($group)->key, 2, new DateTimeImmutable('2025-10-09 08:00:00'), 'Two of Us'));

        self::assertSame(2, $time->puzzlersCount);
        self::assertSame(PuzzlingType::Duo, $time->puzzlingType);
        self::assertNotNull($time->puzzlingTeam);
    }

    public function testAClaimedSoloNeverBecomesAnybodyElse(): void
    {
        $time = $this->importedSoloTime();
        $player = self::getContainer()->get(EntityManagerInterface::class)->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);
        $time->slotClaimedBy($player, firstTry: false);

        $this->expectException(\LogicException::class);
        $time->becomeAnonymousSolo();
    }

    private function importedSoloTime(): PuzzleSolvingTime
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_500_01);
        $competition = $entityManager->find(Competition::class, CompetitionFixture::COMPETITION_WJPC_2024);
        $round = $entityManager->find(CompetitionRound::class, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertNotNull($puzzle);
        self::assertNotNull($competition);
        self::assertNotNull($round);

        return PuzzleSolvingTime::importedSoloFromEvent(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            competition: $competition,
            competitionRound: $round,
            secondsToSolve: 2405,
            solvedAt: new DateTimeImmutable('2025-10-08 19:00:00'),
        );
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
