<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleHistory;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\PuzzleHistoryChange;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Tests\ProposesPuzzleNames;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHistoryEntryKind;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetPuzzleHistoryTest extends KernelTestCase
{
    use ProposesPuzzleNames;

    private MessageBusInterface $messageBus;
    private GetPuzzleHistory $getPuzzleHistory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->getPuzzleHistory = self::getContainer()->get(GetPuzzleHistory::class);
    }

    public function testAnEditComesFirstAndAnOlderApprovalShowsItsProposal(): void
    {
        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_500_02,
            editorId: PlayerFixture::PLAYER_ADMIN,
            values: new PuzzleRecordValues(
                name: 'Puzzle 2 (2024 edition)',
                nameLanguage: null,
                alternativeNames: new PuzzleNames(),
                manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                piecesCount: 500,
                eans: EanList::fromStored('4005556123456'),
                brandCodes: BrandCodeList::fromStored(null),
            ),
            note: 'Year on the box',
        ));

        $entries = $this->getPuzzleHistory->forPuzzle(PuzzleFixture::PUZZLE_500_02);

        $edit = $entries[0];
        self::assertSame(PuzzleHistoryEntryKind::Edited, $edit->kind);
        self::assertSame('Year on the box', $edit->note);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $edit->byId);
        self::assertEquals([new PuzzleHistoryChange('Name', 'Puzzle 2', 'Puzzle 2 (2024 edition)')], $edit->changes);

        // The fixture's approved change request has no line in the decision log - its proposal is what is known
        $approval = $entries[1];
        self::assertSame(PuzzleHistoryEntryKind::ChangeRequestApproved, $approval->kind);
        self::assertSame(PuzzleReportFixture::CHANGE_REQUEST_APPROVED, $approval->changeRequestId);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $approval->proposedById);
        self::assertSame(['Name', 'Pieces'], array_map(static fn (PuzzleHistoryChange $change): string => $change->label, $approval->proposal));
        self::assertSame('Already Approved Name', $approval->proposal[0]->after);
    }

    public function testAMergeShowsOnTheSurvivorAndOnThePuzzleMergedAway(): void
    {
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: PuzzleReportFixture::MERGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_01,
            mergedName: 'Merged Name',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            selectedImagePuzzleId: null,
            decisionNote: 'Same box photo',
        ));

        $merge = $this->getPuzzleHistory->forPuzzle(PuzzleFixture::PUZZLE_500_01)[0];
        self::assertSame(PuzzleHistoryEntryKind::MergeApproved, $merge->kind);
        self::assertSame('Same box photo', $merge->note);
        self::assertSame(PuzzleReportFixture::MERGE_REQUEST_PENDING, $merge->mergeRequestId);
        self::assertCount(1, $merge->puzzles);
        self::assertSame(PuzzleFixture::PUZZLE_500_02, $merge->puzzles[0]->puzzleId);
        self::assertSame('Puzzle 2', $merge->puzzles[0]->name);
        self::assertContainsEquals(new PuzzleHistoryChange('Name', 'Puzzle 1', 'Merged Name'), $merge->changes);
        self::assertGreaterThan(0, $merge->movedRecords['solving times'] ?? 0);

        $mergedAway = array_values(array_filter(
            $this->getPuzzleHistory->forPuzzle(PuzzleFixture::PUZZLE_500_02),
            static fn ($entry): bool => $entry->kind === PuzzleHistoryEntryKind::MergedAway,
        ));
        self::assertCount(1, $mergedAway);
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $mergedAway[0]->puzzles[0]->puzzleId);
        self::assertSame('Merged Name', $mergedAway[0]->puzzles[0]->name);
    }

    public function testARequestTheMergeLeftNothingToDoForShowsAsClosedByItself(): void
    {
        $sameAgain = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $sameAgain,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_02,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_01],
        ));

        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: PuzzleReportFixture::MERGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_01,
            mergedName: 'Merged Name',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: null,
            selectedImagePuzzleId: null,
        ));

        $closed = array_values(array_filter(
            $this->getPuzzleHistory->forPuzzle(PuzzleFixture::PUZZLE_500_01),
            static fn ($entry): bool => $entry->kind === PuzzleHistoryEntryKind::MergeOutdated,
        ));
        self::assertCount(1, $closed);
        self::assertSame($sameAgain, $closed[0]->mergeRequestId);
        self::assertNull($closed[0]->byId);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $closed[0]->proposedById);
        self::assertSame([PuzzleFixture::PUZZLE_500_02], array_map(static fn ($puzzle): string => $puzzle->puzzleId, $closed[0]->puzzles));
    }

    public function testAnEditOfTheOtherNamesShowsTheListWithLanguages(): void
    {
        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            editorId: PlayerFixture::PLAYER_ADMIN,
            values: new PuzzleRecordValues(
                name: 'Puzzle 7',
                nameLanguage: null,
                alternativeNames: new PuzzleNames([
                    new PuzzleName('KOUZELNÁ ZAHRADA', 'cs'),
                    new PuzzleName(PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'de'),
                ]),
                manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                piecesCount: 1000,
                eans: EanList::fromStored(null),
                brandCodes: BrandCodeList::fromStored(null),
            ),
        ));

        $edit = $this->getPuzzleHistory->forPuzzle(PuzzleFixture::PUZZLE_1000_02)[0];

        self::assertSame(PuzzleHistoryEntryKind::Edited, $edit->kind);
        self::assertEquals([new PuzzleHistoryChange(
            'Other names',
            'Kouzelná zahrada (cs), Zauberhafter Garten (de)',
            'KOUZELNÁ ZAHRADA (cs), Zauberhafter Garten (de)',
        )], $edit->changes);
    }

    public function testADecisionLoggedBeforeTheNamesListShowsItsSingleAlternativeName(): void
    {
        $container = self::getContainer();
        $container->get(PuzzleModerationDecisionRecorder::class)->record(
            action: PuzzleModerationAction::PuzzleEdited,
            decidedBy: $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_ADMIN),
            puzzleId: Uuid::fromString(PuzzleFixture::PUZZLE_500_03),
            puzzleName: 'Puzzle 3',
            // PuzzleRecordUpdater::snapshot() as it was until 2026-10
            details: [
                'image' => 'keep',
                'before' => ['name' => 'Puzzle 3', 'alternativeName' => null, 'manufacturerId' => null, 'manufacturerName' => null, 'piecesCount' => 500, 'ean' => null, 'identificationNumber' => null, 'image' => null],
                'after' => ['name' => 'Puzzle 3', 'alternativeName' => 'Třetí puzzle', 'manufacturerId' => null, 'manufacturerName' => null, 'piecesCount' => 500, 'ean' => null, 'identificationNumber' => null, 'image' => null],
            ],
        );
        $container->get(EntityManagerInterface::class)->flush();

        $edit = $this->getPuzzleHistory->forPuzzle(PuzzleFixture::PUZZLE_500_03)[0];

        self::assertSame(PuzzleHistoryEntryKind::Edited, $edit->kind);
        self::assertEquals([new PuzzleHistoryChange('Other names', null, 'Třetí puzzle')], $edit->changes);
    }

    public function testSnapshotsOfBothShapesAreRead(): void
    {
        self::assertEquals(
            [new PuzzleHistoryChange('Other names', 'Old single name', 'Old single name (cs)')],
            PuzzleHistoryChange::between(
                ['alternativeName' => 'Old single name'],
                ['alternativeNames' => [['name' => 'Old single name', 'language' => 'cs']], 'nameLanguage' => null],
            ),
        );
        self::assertEquals(
            [new PuzzleHistoryChange('Name language', null, 'cs')],
            PuzzleHistoryChange::between(
                ['nameLanguage' => null, 'alternativeNames' => []],
                ['nameLanguage' => 'cs', 'alternativeNames' => []],
            ),
        );
        // Neither shape recorded, or a broken value: left out, never a crash
        self::assertSame([], PuzzleHistoryChange::between(['name' => 'A'], ['name' => 'A']));
        self::assertSame([], PuzzleHistoryChange::between(['alternativeNames' => 'broken'], ['alternativeNames' => null]));
    }

    public function testARejectedNamesSuggestionShowsTheNamesItProposed(): void
    {
        $suggestionId = self::proposeOtherName(PuzzleFixture::PUZZLE_1000_02, PlayerFixture::PLAYER_REGULAR, 'Jardín mágico', 'es');
        $this->messageBus->dispatch(new RejectPuzzleChangeRequest(
            changeRequestId: $suggestionId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            rejectionReason: 'Not on any box',
        ));

        $entries = $this->getPuzzleHistory->forPuzzle(PuzzleFixture::PUZZLE_1000_02);

        self::assertSame(PuzzleHistoryEntryKind::ChangeRequestRejected, $entries[0]->kind);
        self::assertEquals([new PuzzleHistoryChange(
            'Other names',
            PuzzleFixture::NAME_CS_MAGIC_GARDEN . ' (cs), ' . PuzzleFixture::NAME_DE_MAGIC_GARDEN . ' (de)',
            PuzzleFixture::NAME_CS_MAGIC_GARDEN . ' (cs), ' . PuzzleFixture::NAME_DE_MAGIC_GARDEN . ' (de), Jardín mágico (es)',
        )], $entries[0]->proposal);
    }

    public function testAnUnknownPuzzleHasNoHistory(): void
    {
        self::assertSame([], $this->getPuzzleHistory->forPuzzle(Uuid::uuid7()->toString()));
        self::assertSame([], $this->getPuzzleHistory->forPuzzle('not-a-uuid'));
    }
}
