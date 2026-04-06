<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddPuzzleToCompetitionRoundHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleRepository $puzzleRepository;
    private CompetitionRoundPuzzleRepository $roundPuzzleRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->puzzleRepository = self::getContainer()->get(PuzzleRepository::class);
        $this->roundPuzzleRepository = self::getContainer()->get(CompetitionRoundPuzzleRepository::class);
    }

    public function testAddExistingPuzzleToRound(): void
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: PuzzleFixture::PUZZLE_300,
            piecesCount: null,
            puzzlePhoto: null,
            puzzleEan: null,
            puzzleIdentificationNumber: null,
            hideUntilRoundStarts: false,
        ));

        $roundPuzzle = $this->roundPuzzleRepository->get($roundPuzzleId->toString());

        self::assertSame(PuzzleFixture::PUZZLE_300, $roundPuzzle->puzzle->id->toString());
        self::assertFalse($roundPuzzle->hideUntilRoundStarts);
        self::assertNull($roundPuzzle->hideMode);
    }

    public function testAddExistingPuzzleWithHideDoesNotModifyPuzzleEntity(): void
    {
        $puzzleBefore = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_300);
        $hideUntilBefore = $puzzleBefore->hideUntil;
        $hideImageUntilBefore = $puzzleBefore->hideImageUntil;

        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: PuzzleFixture::PUZZLE_300,
            piecesCount: null,
            puzzlePhoto: null,
            puzzleEan: null,
            puzzleIdentificationNumber: null,
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));

        $roundPuzzle = $this->roundPuzzleRepository->get($roundPuzzleId->toString());

        self::assertTrue($roundPuzzle->hideUntilRoundStarts);
        self::assertSame(PuzzleHideMode::Entirely, $roundPuzzle->hideMode);

        // Puzzle entity must NOT be modified for existing puzzles
        $puzzleAfter = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_300);
        self::assertSame($hideUntilBefore, $puzzleAfter->hideUntil);
        self::assertSame($hideImageUntilBefore, $puzzleAfter->hideImageUntil);
    }

    public function testAddExistingPuzzleWithHideImageOnlyDoesNotModifyPuzzleEntity(): void
    {
        $puzzleBefore = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_300);
        $hideUntilBefore = $puzzleBefore->hideUntil;
        $hideImageUntilBefore = $puzzleBefore->hideImageUntil;

        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: PuzzleFixture::PUZZLE_300,
            piecesCount: null,
            puzzlePhoto: null,
            puzzleEan: null,
            puzzleIdentificationNumber: null,
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::ImageOnly,
        ));

        $roundPuzzle = $this->roundPuzzleRepository->get($roundPuzzleId->toString());

        self::assertTrue($roundPuzzle->hideUntilRoundStarts);
        self::assertSame(PuzzleHideMode::ImageOnly, $roundPuzzle->hideMode);

        // Puzzle entity must NOT be modified for existing puzzles
        $puzzleAfter = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_300);
        self::assertSame($hideUntilBefore, $puzzleAfter->hideUntil);
        self::assertSame($hideImageUntilBefore, $puzzleAfter->hideImageUntil);
    }

    public function testNewPuzzleWithHideEntirelySetsPuzzleHideUntil(): void
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Secret Competition Puzzle',
            piecesCount: 1000,
            puzzlePhoto: null,
            puzzleEan: null,
            puzzleIdentificationNumber: null,
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));

        $roundPuzzle = $this->roundPuzzleRepository->get($roundPuzzleId->toString());

        self::assertTrue($roundPuzzle->hideUntilRoundStarts);
        self::assertSame(PuzzleHideMode::Entirely, $roundPuzzle->hideMode);

        // New puzzle should have hideUntil set platform-wide
        $puzzle = $roundPuzzle->puzzle;
        self::assertNotNull($puzzle->hideUntil);
        self::assertNull($puzzle->hideImageUntil);
        self::assertSame($roundPuzzle->round->startsAt, $puzzle->hideUntil);
    }

    public function testNewPuzzleWithHideImageOnlySetsPuzzleHideImageUntil(): void
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Another Secret Puzzle',
            piecesCount: 500,
            puzzlePhoto: null,
            puzzleEan: null,
            puzzleIdentificationNumber: null,
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::ImageOnly,
        ));

        $roundPuzzle = $this->roundPuzzleRepository->get($roundPuzzleId->toString());

        self::assertTrue($roundPuzzle->hideUntilRoundStarts);
        self::assertSame(PuzzleHideMode::ImageOnly, $roundPuzzle->hideMode);

        // New puzzle should have hideImageUntil set platform-wide
        $puzzle = $roundPuzzle->puzzle;
        self::assertNotNull($puzzle->hideImageUntil);
        self::assertNull($puzzle->hideUntil);
        self::assertSame($roundPuzzle->round->startsAt, $puzzle->hideImageUntil);
    }

    public function testNewPuzzleWithoutHideDoesNotSetPuzzleHideFields(): void
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Visible New Puzzle',
            piecesCount: 500,
            puzzlePhoto: null,
            puzzleEan: null,
            puzzleIdentificationNumber: null,
            hideUntilRoundStarts: false,
        ));

        $roundPuzzle = $this->roundPuzzleRepository->get($roundPuzzleId->toString());

        self::assertFalse($roundPuzzle->hideUntilRoundStarts);
        self::assertNull($roundPuzzle->hideMode);

        $puzzle = $roundPuzzle->puzzle;
        self::assertNull($puzzle->hideUntil);
        self::assertNull($puzzle->hideImageUntil);
    }

    public function testNewPuzzleWithNewBrandIsCreated(): void
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: 'Brand New Manufacturer',
            puzzle: 'Brand New Puzzle',
            piecesCount: 750,
            puzzlePhoto: null,
            puzzleEan: '1234567890123',
            puzzleIdentificationNumber: 'ID-001',
            hideUntilRoundStarts: false,
        ));

        $roundPuzzle = $this->roundPuzzleRepository->get($roundPuzzleId->toString());
        $puzzle = $roundPuzzle->puzzle;

        self::assertSame('Brand New Puzzle', $puzzle->name);
        self::assertSame(750, $puzzle->piecesCount);
        self::assertSame('1234567890123', $puzzle->ean);
        self::assertSame('ID-001', $puzzle->identificationNumber);
        self::assertFalse($puzzle->approved);
        self::assertSame('Brand New Manufacturer', $puzzle->manufacturer?->name);
    }

    public function testAddExistingPuzzleToSeriesEditionRound(): void
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionSeriesFixture::ROUND_EJJ_69,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: PuzzleFixture::PUZZLE_300,
            piecesCount: null,
            puzzlePhoto: null,
            puzzleEan: null,
            puzzleIdentificationNumber: null,
            hideUntilRoundStarts: false,
        ));

        $roundPuzzle = $this->roundPuzzleRepository->get($roundPuzzleId->toString());

        self::assertSame(PuzzleFixture::PUZZLE_300, $roundPuzzle->puzzle->id->toString());
        self::assertSame(CompetitionSeriesFixture::ROUND_EJJ_69, $roundPuzzle->round->id->toString());
    }
}
