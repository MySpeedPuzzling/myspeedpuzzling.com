<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\BackfillRoundPuzzleReveals;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The organiser's reveal controls: the round puzzle's one reveal moment and the puzzle's site-wide hide dates never
 * drift apart - reveal-time edits, manual reveal, "Reveal now", hide-mode changes, removal, the backfill.
 */
final class RoundPuzzleRevealTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testScheduledRevealMovesThePuzzlesHideDates(): void
    {
        $roundPuzzleId = $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_03, hidesEverywhere: true);
        $moment = new DateTimeImmutable('+3 days')->setTime(13, 15);

        $this->changeReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Scheduled, $moment);

        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        self::assertSame(RoundPuzzleReveal::Scheduled, $roundPuzzle->revealMode);
        self::assertSame($moment->getTimestamp(), $roundPuzzle->revealsAt()?->getTimestamp());
        self::assertSame($moment->getTimestamp(), $roundPuzzle->puzzle->hideUntil?->getTimestamp());
        self::assertSame($moment->getTimestamp(), $roundPuzzle->puzzle->hideImageUntil?->getTimestamp());
    }

    public function testManualRevealKeepsThePuzzleHiddenUntilRevealedNow(): void
    {
        $roundPuzzleId = $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_03, hidesEverywhere: true);

        $this->changeReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null);

        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        self::assertNull($roundPuzzle->revealsAt());
        // No automatic reveal ever - not even long after the round
        self::assertTrue($roundPuzzle->isHiddenAt(new DateTimeImmutable('+5 years')));
        self::assertEquals(new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED), $roundPuzzle->puzzle->hideUntil);

        $this->messageBus->dispatch(new RevealRoundPuzzleNow($roundPuzzleId));
        $this->entityManager->clear();

        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        $now = new DateTimeImmutable();
        self::assertFalse($roundPuzzle->isHiddenAt($now));
        self::assertNotNull($roundPuzzle->puzzle->hideUntil);
        self::assertLessThanOrEqual($now, $roundPuzzle->puzzle->hideUntil);
        self::assertEquals($roundPuzzle->revealsAt(), $roundPuzzle->puzzle->hideUntil);
    }

    public function testChangingTheHideModeMovesTheHideToThePicture(): void
    {
        $roundPuzzleId = $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_03, hidesEverywhere: true);

        $this->changeReveal($roundPuzzleId, PuzzleHideMode::ImageOnly, RoundPuzzleReveal::Automatic, null);

        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        self::assertNull($roundPuzzle->puzzle->hideUntil);
        self::assertEquals($roundPuzzle->round->automaticRevealAt(), $roundPuzzle->puzzle->hideImageUntil);
    }

    public function testCataloguePuzzleIsNeverHiddenBeyondTheEventPages(): void
    {
        $roundPuzzleId = $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_03, hidesEverywhere: false);

        $this->changeReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null);

        $roundPuzzle = $this->roundPuzzle($roundPuzzleId);
        self::assertTrue($roundPuzzle->isHiddenAt(new DateTimeImmutable()));
        self::assertNull($roundPuzzle->puzzle->hideUntil);
        self::assertNull($roundPuzzle->puzzle->hideImageUntil);
    }

    public function testRemovingThePuzzleFromTheRoundNeverRevealsIt(): void
    {
        $roundPuzzleId = $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_03, hidesEverywhere: true);
        $revealsAt = $this->roundPuzzle($roundPuzzleId)->revealsAt();
        self::assertNotNull($revealsAt);

        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound($roundPuzzleId));
        $this->entityManager->clear();

        $puzzle = $this->puzzle(PuzzleFixture::PUZZLE_500_03);
        self::assertSame($revealsAt->getTimestamp(), $puzzle->hideUntil?->getTimestamp());
        self::assertSame($revealsAt->getTimestamp(), $puzzle->hideImageUntil?->getTimestamp());
    }

    public function testBackfillMarksPuzzlesTheRoundCreatedAndResyncsTheirDates(): void
    {
        $round = $this->round();

        // Created for the round before the reveal model: hide date left at a start the round no longer has
        $drifted = $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_03, hidesEverywhere: false);
        $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil = $round->startsAt->modify('-8 hours');

        // A placeholder with its own far-future date, also in a round - not the round's
        $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_04, hidesEverywhere: false);
        $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideUntil = new DateTimeImmutable('2099-01-01 00:00:00');

        $this->entityManager->flush();
        $this->entityManager->clear();

        $dryRun = $this->backfill(dryRun: true);
        self::assertCount(1, $dryRun);
        self::assertFalse($this->roundPuzzle($drifted)->hidesEverywhere);

        $changes = $this->backfill(dryRun: false);
        self::assertCount(1, $changes);

        $roundPuzzle = $this->roundPuzzle($drifted);
        self::assertTrue($roundPuzzle->hidesEverywhere);
        self::assertEquals($roundPuzzle->round->automaticRevealAt(), $roundPuzzle->puzzle->hideUntil);
        self::assertEquals(new DateTimeImmutable('2099-01-01 00:00:00'), $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideUntil);

        // Idempotent
        self::assertSame([], $this->backfill(dryRun: false));
    }

    /**
     * @return list<string>
     */
    private function backfill(bool $dryRun): array
    {
        $envelope = $this->messageBus->dispatch(new BackfillRoundPuzzleReveals(dryRun: $dryRun));
        $this->entityManager->clear();

        /** @var list<string> $changes */
        $changes = $envelope->last(HandledStamp::class)?->getResult();

        return $changes;
    }

    private function changeReveal(
        string $roundPuzzleId,
        PuzzleHideMode $hideMode,
        RoundPuzzleReveal $revealMode,
        null|DateTimeImmutable $scheduledAt,
    ): void {
        $this->messageBus->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId, $hideMode, $revealMode, $scheduledAt));
        $this->entityManager->clear();
    }

    private function secretRoundPuzzle(string $puzzleId, bool $hidesEverywhere): string
    {
        $roundPuzzle = new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $this->round(),
            puzzle: $this->puzzle($puzzleId),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            hidesEverywhere: $hidesEverywhere,
        );
        $this->entityManager->persist($roundPuzzle);
        $this->entityManager->flush();

        return $roundPuzzle->id->toString();
    }

    private function round(): CompetitionRound
    {
        $round = $this->entityManager->find(CompetitionRound::class, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertNotNull($round);

        return $round;
    }

    private function roundPuzzle(string $roundPuzzleId): CompetitionRoundPuzzle
    {
        $roundPuzzle = $this->entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle;
    }

    private function puzzle(string $puzzleId): Puzzle
    {
        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        return $puzzle;
    }
}
