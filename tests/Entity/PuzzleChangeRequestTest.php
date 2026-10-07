<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PuzzleChangeRequest::nothingLeftToApply() - when a pending proposal closes as already applied (OutdatedPuzzleRequests).
 */
final class PuzzleChangeRequestTest extends KernelTestCase
{
    private Puzzle $puzzle;

    protected function setUp(): void
    {
        self::bootKernel();
        // Other names in Czech and German (PuzzleFixture)
        $this->puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_02);
    }

    public function testEveryProposedValueThePuzzleHasLeavesNothing(): void
    {
        $request = $this->proposal(
            proposedName: $this->puzzle->name,
            proposedPiecesCount: $this->puzzle->piecesCount,
            proposedEan: $this->puzzle->eans()->toStored() ?? '',
            proposedIdentificationNumber: $this->puzzle->brandCodes()->toStored() ?? '',
        );

        self::assertTrue($request->nothingLeftToApply());
    }

    public function testOneValueThePuzzleDoesNotHaveIsLeft(): void
    {
        self::assertFalse($this->proposal(proposedName: $this->puzzle->name, proposedPiecesCount: $this->puzzle->piecesCount + 1)->nothingLeftToApply());
        self::assertFalse($this->proposal(proposedEan: '4005556123456')->nothingLeftToApply());
    }

    public function testAProposalOfNothingIsNoProposalToClose(): void
    {
        self::assertFalse($this->proposal()->nothingLeftToApply());
    }

    public function testAProposedImageIsAlwaysLeft(): void
    {
        self::assertFalse($this->proposal(proposedName: $this->puzzle->name, proposedImage: 'proposal.jpg')->nothingLeftToApply());
    }

    public function testTheBrand(): void
    {
        $manufacturers = self::getContainer()->get(ManufacturerRepository::class);
        $current = $this->puzzle->manufacturer;
        self::assertNotNull($current);

        self::assertTrue($this->proposal(proposedManufacturer: $current)->nothingLeftToApply());

        $other = $manufacturers->get($current->id->toString() === ManufacturerFixture::MANUFACTURER_RAVENSBURGER ? ManufacturerFixture::MANUFACTURER_TREFL : ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        self::assertFalse($this->proposal(proposedManufacturer: $other)->nothingLeftToApply());

        // The brand it created was deleted unused - nothing to compare with, a moderator decides
        self::assertFalse($this->proposal(createdManufacturerName: 'Brand Nobody Approved')->nothingLeftToApply());
    }

    public function testOtherNamesCountAsAppliedOnlyWhenApplyingThemChangesNothing(): void
    {
        $names = $this->puzzle->alternativeNames();
        self::assertCount(2, $names);

        // Proposed the list the puzzle has now - from a list without them
        self::assertTrue($this->proposal(proposedAlternativeNames: $names, originalAlternativeNames: new PuzzleNames())->nothingLeftToApply());

        // One more name the puzzle does not have
        $more = $names->union(new PuzzleNames([new PuzzleName('Le jardin magique', 'fr')]));
        self::assertFalse($this->proposal(proposedAlternativeNames: $more, originalAlternativeNames: $names)->nothingLeftToApply());

        // A name the puzzle still has, proposed to be removed
        $fewer = new PuzzleNames([$names->all()[0]]);
        self::assertFalse($this->proposal(proposedAlternativeNames: $fewer, originalAlternativeNames: $names)->nothingLeftToApply());

        // The main title's language proposed differently from the puzzle's
        self::assertFalse($this->proposal(
            proposedAlternativeNames: $names,
            originalAlternativeNames: $names,
            proposedNameLanguage: 'de',
            originalNameLanguage: $this->puzzle->nameLanguage,
        )->nothingLeftToApply());
    }

    public function testAMergeMovesTheRequestOntoTheSurvivorAndRemembersWhereItWasFiled(): void
    {
        $survivor = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_03);
        $request = $this->proposal(proposedPiecesCount: $survivor->piecesCount);

        $request->puzzleMergedInto($survivor);
        self::assertSame($survivor, $request->puzzle);
        self::assertSame(PuzzleFixture::PUZZLE_1000_02, $request->mergedFromPuzzleId?->toString());
        // Judged against the puzzle it is about now
        self::assertTrue($request->nothingLeftToApply());

        // Merged once more - it was still filed on the first one
        $request->puzzleMergedInto(self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_04));
        self::assertSame(PuzzleFixture::PUZZLE_1000_02, $request->mergedFromPuzzleId?->toString());
    }

    private function proposal(
        null|string $proposedName = null,
        null|Manufacturer $proposedManufacturer = null,
        null|int $proposedPiecesCount = null,
        null|string $proposedEan = null,
        null|string $proposedIdentificationNumber = null,
        null|string $proposedImage = null,
        null|PuzzleNames $proposedAlternativeNames = null,
        null|string $proposedNameLanguage = null,
        null|PuzzleNames $originalAlternativeNames = null,
        // false = the same as proposed: the language is not part of the proposal
        null|false|string $originalNameLanguage = false,
        null|string $createdManufacturerName = null,
    ): PuzzleChangeRequest {
        return new PuzzleChangeRequest(
            id: Uuid::uuid7(),
            puzzle: $this->puzzle,
            reporter: self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR),
            submittedAt: new DateTimeImmutable(),
            proposedName: $proposedName,
            proposedManufacturer: $proposedManufacturer,
            proposedPiecesCount: $proposedPiecesCount,
            proposedEan: $proposedEan,
            proposedIdentificationNumber: $proposedIdentificationNumber,
            proposedImage: $proposedImage,
            proposedAlternativeNames: $proposedAlternativeNames?->toArray(),
            proposedNameLanguage: $proposedNameLanguage,
            originalName: $this->puzzle->name,
            originalPiecesCount: $this->puzzle->piecesCount,
            originalAlternativeNames: $originalAlternativeNames?->toArray(),
            originalNameLanguage: $originalNameLanguage === false ? $proposedNameLanguage : $originalNameLanguage,
            createdManufacturerName: $createdManufacturerName,
        );
    }
}
