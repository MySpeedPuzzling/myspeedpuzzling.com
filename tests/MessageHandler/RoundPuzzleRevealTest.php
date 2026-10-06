<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\RevealMomentAlreadyPassed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\BackfillRoundPuzzleReveals;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The organiser's reveal controls: a secret puzzle's site-wide hide dates never drift from what its rounds promise -
 * reveal-time edits, manual reveal, "Reveal now", hide-mode changes, a puzzle in several rounds, removal, round
 * deletion, the backfill.
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

    public function testScheduledRevealInThePastIsRefused(): void
    {
        $roundPuzzleId = $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_03, hidesEverywhere: true);
        $hiddenUntil = $this->roundPuzzle($roundPuzzleId)->puzzle->hideUntil;

        try {
            $this->changeReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Scheduled, new DateTimeImmutable('-1 minute'));
            self::fail('A reveal in the past must be refused - that is "Reveal now"');
        } catch (RevealMomentAlreadyPassed) {
        }

        $this->entityManager->clear();
        self::assertEquals($hiddenUntil, $this->roundPuzzle($roundPuzzleId)->puzzle->hideUntil);
    }

    public function testRevealedRoundPuzzleIsNeverHiddenAgain(): void
    {
        $roundPuzzleId = $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_03, hidesEverywhere: true);
        $this->messageBus->dispatch(new RevealRoundPuzzleNow($roundPuzzleId));
        $this->entityManager->clear();

        $this->expectException(RoundPuzzleAlreadyRevealed::class);
        $this->changeReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null);
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

    public function testPuzzleInTwoRoundsStaysHiddenUntilTheLatestReveal(): void
    {
        $puzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleHideMode::ImageOnly);
        // The same secret puzzle, hidden entirely, in a round a day later
        $later = $this->addToRound(CompetitionRoundFixture::ROUND_CZECH_FINAL, $puzzleId, PuzzleHideMode::Entirely);
        $laterReveal = $this->roundPuzzle($later)->revealsAt();
        self::assertNotNull($laterReveal);

        $puzzle = $this->puzzle($puzzleId);
        // "Entirely" wins, the latest reveal wins - never the round saved last
        self::assertSame($laterReveal->getTimestamp(), $puzzle->hideUntil?->getTimestamp());
        self::assertSame($laterReveal->getTimestamp(), $puzzle->hideImageUntil?->getTimestamp());
    }

    public function testRemovingThePuzzleFromTheEarlierRoundNeverRevealsItBeforeTheLater(): void
    {
        $puzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleHideMode::Entirely);
        $earlier = $this->roundPuzzleOf(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $puzzleId);
        $later = $this->addToRound(CompetitionRoundFixture::ROUND_CZECH_FINAL, $puzzleId, PuzzleHideMode::Entirely);
        $laterReveal = $this->roundPuzzle($later)->revealsAt();
        self::assertNotNull($laterReveal);

        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound($earlier));
        $this->entityManager->clear();

        self::assertSame($laterReveal->getTimestamp(), $this->puzzle($puzzleId)->hideUntil?->getTimestamp());
    }

    public function testDeletingTheEarlierRoundNeverRevealsItBeforeTheLater(): void
    {
        $puzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleHideMode::Entirely);
        $later = $this->addToRound(CompetitionRoundFixture::ROUND_CZECH_FINAL, $puzzleId, PuzzleHideMode::Entirely);
        $laterReveal = $this->roundPuzzle($later)->revealsAt();
        self::assertNotNull($laterReveal);

        $this->messageBus->dispatch(new DeleteCompetitionRound(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION));
        $this->entityManager->clear();

        self::assertSame($laterReveal->getTimestamp(), $this->puzzle($puzzleId)->hideUntil?->getTimestamp());
    }

    public function testManualRevealInOneRoundHoldsThePuzzleUntilThatRoundRevealsIt(): void
    {
        $puzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleHideMode::Entirely);
        $manual = $this->roundPuzzleOf(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $puzzleId);
        $later = $this->addToRound(CompetitionRoundFixture::ROUND_CZECH_FINAL, $puzzleId, PuzzleHideMode::Entirely);
        $this->changeReveal($manual, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null);

        self::assertEquals(new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED), $this->puzzle($puzzleId)->hideUntil);

        // Revealing the manual round hands the puzzle to the other round's reveal - not to the 9999 placeholder
        $this->messageBus->dispatch(new RevealRoundPuzzleNow($manual));
        $this->entityManager->clear();

        $laterReveal = $this->roundPuzzle($later)->revealsAt();
        self::assertNotNull($laterReveal);
        self::assertSame($laterReveal->getTimestamp(), $this->puzzle($puzzleId)->hideUntil?->getTimestamp());
    }

    public function testBackfillMarksOnlyTheRoundThatCreatedThePuzzle(): void
    {
        $puzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleHideMode::Entirely);
        $creating = $this->roundPuzzleOf(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $puzzleId);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        // A second round added the same puzzle a day later (its id says so)
        $second = new CompetitionRoundPuzzle(
            id: Uuid::uuid7(new DateTimeImmutable('+1 day')),
            round: $this->round(CompetitionRoundFixture::ROUND_WJPC_FINAL),
            puzzle: $this->puzzle($puzzleId),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::ImageOnly,
        );
        $this->entityManager->persist($second);

        // As before the reveal model: no row marked, and the round was postponed by 10 days after the puzzle was added
        $this->roundPuzzle($creating)->hidesEverywhere = false;
        $this->puzzle($puzzleId)->keepSecretUntil($round->startsAt->modify('-10 days'), $round->startsAt->modify('-10 days'));
        // A placeholder hidden by hand, also in a round
        $this->secretRoundPuzzle(PuzzleFixture::PUZZLE_500_04, hidesEverywhere: false);
        $this->puzzle(PuzzleFixture::PUZZLE_500_04)->keepSecretUntil(new DateTimeImmutable('2099-01-01 00:00:00'), new DateTimeImmutable('2099-01-01 00:00:00'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $dryRun = $this->backfill(dryRun: true);
        self::assertCount(1, $dryRun['changes']);
        self::assertStringContainsString($creating, $dryRun['changes'][0]);
        self::assertFalse($this->roundPuzzle($creating)->hidesEverywhere);
        self::assertTrue($this->containsLineFor($dryRun['unmatched'], PuzzleFixture::PUZZLE_500_04));

        $changes = $this->backfill(dryRun: false);
        self::assertCount(1, $changes['changes']);
        self::assertTrue($this->roundPuzzle($creating)->hidesEverywhere);
        self::assertFalse($this->roundPuzzle($second->id->toString())->hidesEverywhere);

        $creatingReveal = $this->roundPuzzle($creating)->revealsAt();
        self::assertNotNull($creatingReveal);
        self::assertSame($creatingReveal->getTimestamp(), $this->puzzle($puzzleId)->hideUntil?->getTimestamp());
        self::assertEquals(new DateTimeImmutable('2099-01-01 00:00:00'), $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideUntil);

        // Idempotent
        self::assertSame([], $this->backfill(dryRun: false)['changes']);
    }

    public function testBackfillLeavesRevealedPuzzlesAlone(): void
    {
        $puzzleId = $this->newSecretPuzzle(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleHideMode::Entirely);
        $creating = $this->roundPuzzleOf(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $puzzleId);
        $this->roundPuzzle($creating)->hidesEverywhere = false;
        $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION)->startsAt = new DateTimeImmutable('-2 days');
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame([], $this->backfill(dryRun: false)['changes']);
    }

    /**
     * @param list<string> $lines
     */
    private function containsLineFor(array $lines, string $puzzleId): bool
    {
        foreach ($lines as $line) {
            if (str_contains($line, $puzzleId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{changes: list<string>, unmatched: list<string>}
     */
    private function backfill(bool $dryRun): array
    {
        $envelope = $this->messageBus->dispatch(new BackfillRoundPuzzleReveals(dryRun: $dryRun));
        $this->entityManager->clear();

        /** @var array{changes: list<string>, unmatched: list<string>} $result */
        $result = $envelope->last(HandledStamp::class)?->getResult();

        return $result;
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

    /**
     * A new puzzle created secret for the round, the way the add-to-round form does it.
     */
    private function newSecretPuzzle(string $roundId, PuzzleHideMode $hideMode): string
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Scrabble Time ' . $roundPuzzleId->toString(),
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
        ));
        $this->entityManager->clear();

        return $this->roundPuzzle($roundPuzzleId->toString())->puzzle->id->toString();
    }

    private function addToRound(string $roundId, string $puzzleId, PuzzleHideMode $hideMode): string
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $puzzleId,
            piecesCount: null,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
        ));
        $this->entityManager->clear();

        return $roundPuzzleId->toString();
    }

    private function roundPuzzleOf(string $roundId, string $puzzleId): string
    {
        $roundPuzzle = $this->entityManager->getRepository(CompetitionRoundPuzzle::class)->findOneBy([
            'round' => $roundId,
            'puzzle' => $puzzleId,
        ]);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle->id->toString();
    }

    private function secretRoundPuzzle(string $puzzleId, bool $hidesEverywhere): string
    {
        $puzzle = $this->puzzle($puzzleId);
        $roundPuzzle = new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION),
            puzzle: $puzzle,
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            hidesEverywhere: $hidesEverywhere,
        );
        $this->entityManager->persist($roundPuzzle);
        self::getContainer()->get(SecretPuzzleHides::class)->resync($puzzle);
        $this->entityManager->flush();

        return $roundPuzzle->id->toString();
    }

    private function round(string $roundId): CompetitionRound
    {
        $round = $this->entityManager->find(CompetitionRound::class, $roundId);
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
