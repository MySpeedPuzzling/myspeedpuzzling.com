<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Librarian (accepted proposals) and Cataloger (approved puzzles) follow catalogue decisions, not logged times - so
 * every decision they count queues an achievement evaluation of the player it credits. Without it the tier waited for
 * the nightly run or the player's next logged time (a player reported 55/50 Librarian with no Platinum, 2026-10-15).
 */
final class CatalogueAchievementWiringTest extends KernelTestCase
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

    public function testApprovingAChangeRequestEvaluatesTheProposer(): void
    {
        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: ['name'],
        ));

        self::assertEvaluated(PlayerFixture::PLAYER_REGULAR);
    }

    public function testApprovingAMergeEvaluatesTheReporter(): void
    {
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
            reporterId: PlayerFixture::PLAYER_WITH_STRIPE,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
        ));

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

        self::assertEvaluated(PlayerFixture::PLAYER_WITH_STRIPE);
    }

    public function testApprovingANewPuzzleEvaluatesItsAdder(): void
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

        self::assertEvaluated($adder->id->toString());
    }

    private function assertEvaluated(string $playerId): void
    {
        $queued = [];

        foreach ($this->asyncTransport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof RecalculateBadgesForPlayer) {
                $queued[] = $message->playerId;
            }
        }

        self::assertContains($playerId, $queued);
    }
}
