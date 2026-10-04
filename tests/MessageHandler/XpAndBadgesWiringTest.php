<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AnswerGuestLink;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Message\RequestGuestLink;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Message\UndoAutoRemoval;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Main's write paths that change results without going through the add / edit / delete handlers keep the XP ledger
 * and the achievements in step (docs/features/xp-levels/README.md) - XP has no cron that would heal a missed one.
 * The messages are async, so this pins what is queued, not what the handlers then do.
 */
final class XpAndBadgesWiringTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private InMemoryTransport $asyncTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->asyncTransport = $transport;
        $this->asyncTransport->reset();
    }

    public function testAnAutomaticallyRemovedCopyIsCompensatedAndUndoRebuildsItsMembers(): void
    {
        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);

        $compensations = $this->sent(CompensateXpForDeletedSolve::class);
        self::assertContains(DuplicateResultsFixture::TIME_CERTAIN_B, array_map(static fn (CompensateXpForDeletedSolve $message): string => $message->solvingTimeId, $compensations));

        $removalId = $this->database->fetchOne(
            'SELECT id FROM result_auto_removal WHERE removed_time_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        );
        self::assertIsString($removalId);

        $this->asyncTransport->reset();
        $this->messageBus->dispatch(new UndoAutoRemoval($removalId, DuplicateResultsFixture::PLAYER_TWINS));

        self::assertContains(DuplicateResultsFixture::PLAYER_TWINS, $this->playerIdsOf(RecalculateXpForPlayer::class));
        self::assertContains(DuplicateResultsFixture::PLAYER_TWINS, $this->playerIdsOf(RecalculateBadgesForPlayer::class));
    }

    public function testMovingAResultToAnotherPuzzleRebuildsTheWholeLedgerAndTakesTheNewPiecesCount(): void
    {
        // TIME_01: PLAYER_REGULAR, solo on PUZZLE_500_01 - moved to a 1000-piece puzzle
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_01,
            competitionId: null,
            time: '00:30:00',
            comment: null,
            groupPlayers: [],
            finishedAt: null,
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
            firstTryResolution: FirstTryResolution::None,
            puzzleId: PuzzleFixture::PUZZLE_1000_05,
        ));

        self::assertSame(1000, $this->database->fetchOne(
            'SELECT pieces_count_snapshot FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        ));

        // The chain on the puzzle it left changes too - the per-puzzle chain rebuild would not see it
        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->playerIdsOf(RecalculateXpForPlayer::class));
        self::assertSame([], $this->sent(RecalculateXpChainForSolve::class));
        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->playerIdsOf(RecalculateBadgesForPlayer::class));
    }

    public function testEveryRegisteredMemberOfAPairResultIsRecalculated(): void
    {
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, PuzzleFixture::PUZZLE_1500_01, ['#player3']);

        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_FAVORITES],
            $this->playerIdsOf(RecalculateBadgesForPlayer::class),
        );
    }

    public function testAnAcceptedGuestLinkRebuildsThePlayerWhoTookTheResultsOver(): void
    {
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, PuzzleFixture::PUZZLE_1500_01, ['Michael']);

        $requestId = Uuid::uuid7();
        $this->messageBus->dispatch(new RequestGuestLink($requestId, PlayerFixture::PLAYER_WITH_STRIPE, 'g:michael', 'player3'));

        $this->asyncTransport->reset();
        $this->messageBus->dispatch(new AnswerGuestLink($requestId->toString(), PlayerFixture::PLAYER_WITH_FAVORITES, true));

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], $this->playerIdsOf(RecalculateXpForPlayer::class));
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], $this->playerIdsOf(RecalculateBadgesForPlayer::class));
    }

    public function testAMergeRebuildsTheChainOfEveryMovedResult(): void
    {
        $movedTimeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_05, []);

        $mergeRequestId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
        ));

        $this->asyncTransport->reset();
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: 'Merged Puzzle Name',
            mergedEan: null,
            mergedIdentificationNumber: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            selectedImagePuzzleId: null,
        ));

        self::assertContains(
            $movedTimeId,
            array_map(static fn (RecalculateXpChainForSolve $message): string => $message->solvingTimeId, $this->sent(RecalculateXpChainForSolve::class)),
        );
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function addTime(string $userId, string $puzzleId, array $groupPlayers): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: '05:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private function sent(string $class): array
    {
        $messages = [];

        foreach ($this->asyncTransport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof $class) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param class-string<RecalculateXpForPlayer|RecalculateBadgesForPlayer> $class
     * @return list<string>
     */
    private function playerIdsOf(string $class): array
    {
        return array_values(array_unique(array_map(
            static fn (RecalculateXpForPlayer|RecalculateBadgesForPlayer $message): string => $message->playerId,
            $this->sent($class),
        )));
    }
}
