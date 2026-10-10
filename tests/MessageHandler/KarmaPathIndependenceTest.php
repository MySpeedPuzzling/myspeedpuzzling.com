<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\RecalculateKarma;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Services\Karma\KarmaRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Karma is derived from the current records (docs/features/karma.md), so it is path independent by construction:
 * after any loop of proposals, approvals, merges and re-adds through the real handlers, the stored player.karma of
 * EVERY player equals a fresh recalculation - a recalculation changes nobody. Repeating a loop earns only what the
 * rule grants for the records the loop leaves behind (see the moderator / reporter note in the anti-farming docs).
 */
final class KarmaPathIndependenceTest extends KernelTestCase
{
    private const string MODERATOR = PlayerFixture::PLAYER_WITH_STRIPE;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;
    private InMemoryTransport $asyncTransport;
    private KarmaRecalculator $karmaRecalculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->karmaRecalculator = $container->get(KarmaRecalculator::class);

        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->asyncTransport = $transport;

        $this->karmaRecalculator->recalculateAll();
        $this->asyncTransport->reset();
    }

    public function testAnApprovedProposalKeepsItsCreditWhenItsPuzzleIsMergedAway(): void
    {
        $proposer = PlayerFixture::PLAYER_REGULAR;
        $before = $this->karma($proposer);

        $proposal = $this->propose($proposer, PuzzleFixture::PUZZLE_500_05, 'Corrected name');
        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: $proposal,
            puzzleId: PuzzleFixture::PUZZLE_500_05,
            reviewerId: self::MODERATOR,
            selectedFields: ['name'],
        ));
        $this->drain();
        self::assertSame($before + 1, $this->karma($proposer));
        $this->assertKarmaMatchesFreshRecalculation();

        $this->merge(PuzzleFixture::PUZZLE_500_04, [PuzzleFixture::PUZZLE_500_05], reporter: PlayerFixture::PLAYER_PRIVATE);
        self::assertSame($before + 1, $this->karma($proposer), 'The proposal moved with the merge and still counts');
        $this->assertKarmaMatchesFreshRecalculation();
    }

    public function testAddingADuplicateWithAnEanAndHavingItMergedAwayKeepsNothing(): void
    {
        $firstAdder = PlayerFixture::PLAYER_REGULAR;
        $lateAdder = PlayerFixture::PLAYER_PRIVATE;
        $original = $this->addAndApprovePuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID, '4006000000011');
        $lateBefore = $this->karma($lateAdder);
        $firstAdderAfterAdding = $this->karma($firstAdder);
        $this->assertKarmaMatchesFreshRecalculation();

        for ($round = 0; $round < 3; $round++) {
            $copy = $this->addAndApprovePuzzle(PlayerFixture::PLAYER_PRIVATE_USER_ID, '4006000000011');
            self::assertSame($lateBefore + 1, $this->karma($lateAdder), 'Approved with an EAN - counted until it turns out to be a copy');
            $this->assertKarmaMatchesFreshRecalculation();

            $this->merge($original, [$copy], reporter: PlayerFixture::PLAYER_ADMIN);
            self::assertSame($lateBefore, $this->karma($lateAdder), 'The later copy is not a contribution - its credit goes');
            self::assertSame($firstAdderAfterAdding, $this->karma($firstAdder), 'The earliest copy keeps its credit');
            $this->assertKarmaMatchesFreshRecalculation();
        }
    }

    public function testAModeratorDecidingTheirOwnProposalsGetsOneEachNeverTwo(): void
    {
        $before = $this->karma(self::MODERATOR);

        for ($round = 1; $round <= 3; $round++) {
            $approved = $this->propose(self::MODERATOR, PuzzleFixture::PUZZLE_1000_01, 'Own proposal ' . $round);
            $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
                changeRequestId: $approved,
                puzzleId: PuzzleFixture::PUZZLE_1000_01,
                reviewerId: self::MODERATOR,
                selectedFields: ['name'],
            ));
            $this->drain();
            $this->assertKarmaMatchesFreshRecalculation();

            $rejected = $this->propose(self::MODERATOR, PuzzleFixture::PUZZLE_1000_02, 'Declined own proposal ' . $round);
            $this->messageBus->dispatch(new RejectPuzzleChangeRequest(
                changeRequestId: $rejected,
                reviewerId: self::MODERATOR,
                rejectionReason: 'Changed my mind.',
            ));
            $this->drain();
            $this->assertKarmaMatchesFreshRecalculation();

            self::assertSame($before + 2 * $round, $this->karma(self::MODERATOR), 'Approve own = 1, reject own = 1 (the decision)');
        }
    }

    private function addAndApprovePuzzle(string $userId, string $ean): string
    {
        $puzzleId = Uuid::uuid7();
        $imagePath = tempnam(sys_get_temp_dir(), 'karma_test_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $imagePath);

        $this->messageBus->dispatch(new AddPuzzle(
            puzzleId: $puzzleId,
            userId: $userId,
            puzzleName: 'Karma loop puzzle',
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            piecesCount: 1000,
            puzzlePhoto: new UploadedFile($imagePath, 'box.jpg', 'image/jpeg', null, true),
            eans: EanList::fromStored($ean),
            brandCodes: BrandCodeList::fromStored(null),
        ));

        $this->messageBus->dispatch(new ApprovePuzzle(
            puzzleId: $puzzleId->toString(),
            reviewerId: self::MODERATOR,
            name: 'Karma loop puzzle',
            nameLanguage: null,
            alternativeNames: new PuzzleNames(),
            piecesCount: 1000,
            eans: EanList::fromStored($ean),
            brandCodes: BrandCodeList::fromStored(null),
        ));
        $this->drain();

        return $puzzleId->toString();
    }

    /**
     * @param list<string> $duplicates
     */
    private function merge(string $survivor, array $duplicates, string $reporter): void
    {
        $mergeRequestId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: $survivor,
            reporterId: $reporter,
            duplicatePuzzleIds: $duplicates,
        ));
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: self::MODERATOR,
            survivorPuzzleId: $survivor,
            mergedName: 'Merged Puzzle Name',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: $this->piecesOf($survivor),
            mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            selectedImagePuzzleId: null,
        ));
        $this->drain();
    }

    private function propose(string $reporterId, string $puzzleId, string $name): string
    {
        $changeRequestId = Uuid::uuid7()->toString();
        $pieces = $this->piecesOf($puzzleId);

        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: $puzzleId,
            reporterId: $reporterId,
            proposedName: $name,
            proposedBrand: null,
            proposedPiecesCount: $pieces,
            proposedEans: EanList::fromStored(null),
            proposedBrandCodes: BrandCodeList::fromStored(null),
            proposedPhoto: null,
            originalAlternativeNames: new PuzzleNames(),
            originalNameLanguage: null,
        ));

        return $changeRequestId;
    }

    /**
     * Handles the queued karma recalculations like the consumer.
     */
    private function drain(): void
    {
        $envelopes = $this->asyncTransport->getSent();
        $this->asyncTransport->reset();

        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof RecalculateKarma) {
                $this->entityManager->clear();
                $this->messageBus->dispatch($message, [new ReceivedStamp('async')]);
            }
        }

        $this->entityManager->clear();
    }

    private function assertKarmaMatchesFreshRecalculation(): void
    {
        self::assertSame(0, $this->karmaRecalculator->recalculateAll(), 'A fresh recalculation changed somebody\'s karma - a write path missed it');
    }

    private function piecesOf(string $puzzleId): int
    {
        $pieces = $this->database->fetchOne('SELECT pieces_count FROM puzzle WHERE id = :id', ['id' => $puzzleId]);
        self::assertIsInt($pieces);

        return $pieces;
    }

    private function karma(string $playerId): int
    {
        $karma = $this->database->fetchOne('SELECT karma FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertIsInt($karma);

        return $karma;
    }
}
