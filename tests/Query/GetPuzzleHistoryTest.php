<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Query\GetPuzzleHistory;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\PuzzleHistoryChange;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\PuzzleHistoryEntryKind;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetPuzzleHistoryTest extends KernelTestCase
{
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
                alternativeName: null,
                manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                piecesCount: 500,
                ean: '4005556123456',
                identificationNumber: null,
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
            mergedEan: null,
            mergedIdentificationNumber: null,
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

    public function testAnEditOfTheOtherNamesShowsTheListWithLanguages(): void
    {
        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            editorId: PlayerFixture::PLAYER_ADMIN,
            values: new PuzzleRecordValues(
                name: 'Puzzle 7',
                alternativeName: 'Kouzelná zahrádka',
                manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                piecesCount: 1000,
                ean: null,
                identificationNumber: null,
            ),
        ));

        $edit = $this->getPuzzleHistory->forPuzzle(PuzzleFixture::PUZZLE_1000_02)[0];

        self::assertSame(PuzzleHistoryEntryKind::Edited, $edit->kind);
        self::assertEquals([new PuzzleHistoryChange(
            'Other names',
            'Kouzelná zahrada (cs), Zauberhafter Garten (de)',
            'Kouzelná zahrádka (cs), Zauberhafter Garten (de)',
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

    public function testAnUnknownPuzzleHasNoHistory(): void
    {
        self::assertSame([], $this->getPuzzleHistory->forPuzzle(Uuid::uuid7()->toString()));
        self::assertSame([], $this->getPuzzleHistory->forPuzzle('not-a-uuid'));
    }
}
