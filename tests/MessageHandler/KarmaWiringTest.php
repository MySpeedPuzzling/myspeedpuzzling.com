<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Message\LinkEanToPuzzle;
use SpeedPuzzling\Web\Message\RecalculateKarma;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Message\RejectPuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Every decision karma counts queues a recalculation for the people it concerns - the proposer AND the moderator
 * (docs/features/karma.md). The message is async, so this pins what is queued; the daily run is only a safety net.
 */
final class KarmaWiringTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private InMemoryTransport $asyncTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->asyncTransport = $transport;
        $this->asyncTransport->reset();
    }

    public function testApprovingAChangeRequestRecalculatesTheProposerAndTheModerator(): void
    {
        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: ['name'],
        ));

        self::assertRecalculated([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN]);
    }

    public function testDecliningAChangeRequestRecalculatesBoth(): void
    {
        $this->messageBus->dispatch(new RejectPuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            rejectionReason: 'Not this puzzle.',
        ));

        self::assertRecalculated([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN]);
    }

    public function testDecliningAMergeRequestRecalculatesBoth(): void
    {
        $this->messageBus->dispatch(new RejectPuzzleMergeRequest(
            mergeRequestId: PuzzleReportFixture::MERGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            rejectionReason: 'Different editions.',
        ));

        self::assertRecalculated([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN]);
    }

    public function testApprovingAMergeRecalculatesTheReporterTheModeratorAndTheAdders(): void
    {
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
            reporterId: PlayerFixture::PLAYER_WITH_STRIPE,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
        ));

        $adders = [];

        foreach ([PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_05] as $puzzleId) {
            $adder = self::getContainer()->get(PuzzleRepository::class)->get($puzzleId)->addedByUser;

            if ($adder !== null) {
                $adders[] = $adder->id->toString();
            }
        }

        $this->asyncTransport->reset();
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: 'Merged Puzzle Name',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            selectedImagePuzzleId: null,
        ));

        self::assertRecalculated([PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN, ...$adders]);
    }

    public function testApprovingANewPuzzleRecalculatesItsAdderAndTheModerator(): void
    {
        $this->messageBus->dispatch(new ApprovePuzzle(
            puzzleId: PuzzleFixture::PUZZLE_UNAPPROVED,
            reviewerId: PlayerFixture::PLAYER_WITH_STRIPE,
            name: 'Approved Name',
            nameLanguage: null,
            alternativeNames: new PuzzleNames(),
            piecesCount: 500,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
        ));

        $adder = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_UNAPPROVED)->addedByUser;
        self::assertNotNull($adder);

        self::assertRecalculated([$adder->id->toString(), PlayerFixture::PLAYER_WITH_STRIPE]);
    }

    public function testADirectEditRecalculatesTheEditor(): void
    {
        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            editorId: PlayerFixture::PLAYER_WITH_STRIPE,
            values: new PuzzleRecordValues(
                name: 'Edited Name',
                nameLanguage: null,
                alternativeNames: new PuzzleNames(),
                manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                piecesCount: 500,
                eans: EanList::fromStored('4005556123452'),
                brandCodes: BrandCodeList::fromStored(null),
            ),
        ));

        self::assertRecalculated([PlayerFixture::PLAYER_WITH_STRIPE]);
    }

    public function testAScannedEanRecalculatesTheScanner(): void
    {
        $this->messageBus->dispatch(new LinkEanToPuzzle(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_WITH_STRIPE, PuzzleFixture::EAN_UNKNOWN));

        self::assertRecalculated([PlayerFixture::PLAYER_WITH_STRIPE]);
    }

    /**
     * @param list<string> $playerIds
     */
    private function assertRecalculated(array $playerIds): void
    {
        $queued = [];

        foreach ($this->asyncTransport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof RecalculateKarma) {
                self::assertNotNull($message->playerIds, 'A decision recalculates the people it concerns, not everybody');
                $queued = [...$queued, ...$message->playerIds];
            }
        }

        foreach ($playerIds as $playerId) {
            self::assertContains($playerId, $queued);
        }
    }
}
