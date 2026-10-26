<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\BorrowRequestNotFound;
use SpeedPuzzling\Web\Message\AnswerBorrowRequest;
use SpeedPuzzling\Web\Message\AskToBorrowPuzzles;
use SpeedPuzzling\Web\Message\BorrowPuzzleFromPlayer;
use SpeedPuzzling\Web\Message\ExpireBorrowRequests;
use SpeedPuzzling\Web\Message\LendPuzzleToPlayer;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Message\WithdrawBorrowRequest;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A borrow request after it was sent (docs/features/wishlist-availability.md): the owner answers, the asker withdraws,
 * it expires, or the puzzle changes hands.
 */
final class BorrowRequestLifecycleTest extends KernelTestCase
{
    use BorrowSeeding;

    private Connection $connection;
    private MessageBusInterface $messageBus;
    private string $asker;
    private string $owner;
    private string $lighthouse;
    private string $garden;
    private string $requestId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);

        $this->asker = $this->seedPlayer('Jana');
        $this->owner = $this->seedPlayer('Anna');
        $this->lighthouse = $this->seedPuzzle(1000, 'Lighthouse');
        $this->garden = $this->seedPuzzle(500, 'Night Garden');

        $this->seedFavorite($this->asker, $this->owner);
        $this->seedCollectionVisibility($this->owner, true);
        $this->seedOwned($this->owner, $this->lighthouse);
        $this->seedOwned($this->owner, $this->garden);
        $this->seedWished($this->asker, $this->lighthouse);
        $this->seedWished($this->asker, $this->garden);

        $this->messageBus->dispatch(new AskToBorrowPuzzles(Uuid::uuid7()->toString(), $this->asker, [$this->owner => [$this->lighthouse, $this->garden]]));

        $requestId = $this->connection->fetchOne('SELECT id FROM borrow_request WHERE asker_id = :asker', ['asker' => $this->asker]);
        self::assertIsString($requestId);
        $this->requestId = $requestId;
    }

    public function testTheOwnerAnswersAndAYesOpensAChatEvenWithTheAskersDirectMessagesOff(): void
    {
        $this->connection->executeStatement('UPDATE player SET allow_direct_messages = false WHERE id = :id', ['id' => $this->asker]);

        $this->messageBus->dispatch(new AnswerBorrowRequest(
            requestId: $this->requestId,
            playerId: $this->owner,
            answers: [
                $this->itemId($this->lighthouse) => 'yes',
                $this->itemId($this->garden) => 'not_owned',
            ],
            removeFromCollection: [$this->itemId($this->garden)],
            locale: 'en',
        ));

        self::assertSame('yes', $this->itemStatus($this->lighthouse));
        self::assertSame('not_owned', $this->itemStatus($this->garden));

        // "I don't have it any more" took it out of the owner's collection, the other one stays
        self::assertSame(
            [$this->lighthouse],
            $this->connection->fetchFirstColumn('SELECT puzzle_id FROM collection_item WHERE player_id = :owner', ['owner' => $this->owner]),
        );

        self::assertSame(1, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :asker AND type = 'BorrowRequestAnswered' AND target_borrow_request_id = :request",
            ['asker' => $this->asker, 'request' => $this->requestId],
        ));

        $conversation = $this->connection->fetchAssociative(
            'SELECT status, initiator_id FROM conversation WHERE (initiator_id = :owner AND recipient_id = :asker)',
            ['owner' => $this->owner, 'asker' => $this->asker],
        );
        self::assertSame(['status' => 'accepted', 'initiator_id' => $this->owner], $conversation);
        self::assertNotNull($this->connection->fetchOne('SELECT replied_at FROM borrow_request WHERE id = :id', ['id' => $this->requestId]));
    }

    public function testAfterABlockTheAnswersAreKeptButNobodyIsToldAndNoChatOpens(): void
    {
        // The asker blocked the owner after asking - the owner must not be able to tell
        $this->seedBlock($this->asker, $this->owner);

        $this->messageBus->dispatch(new AnswerBorrowRequest(
            requestId: $this->requestId,
            playerId: $this->owner,
            answers: [$this->itemId($this->lighthouse) => 'yes'],
            openChat: true,
        ));

        self::assertSame('yes', $this->itemStatus($this->lighthouse));
        self::assertSame(0, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :asker AND type = 'BorrowRequestAnswered'",
            ['asker' => $this->asker],
        ));
        self::assertSame(0, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM conversation WHERE (initiator_id = :owner AND recipient_id = :asker) OR (initiator_id = :asker AND recipient_id = :owner)',
            ['owner' => $this->owner, 'asker' => $this->asker],
        ));
    }

    public function testExpiryTellsNobodyAfterABlock(): void
    {
        $this->seedBlock($this->owner, $this->asker);
        $this->connection->executeStatement(
            "UPDATE borrow_request SET expires_at = " . TestClock::SQL_NOW . " - INTERVAL '1 day' WHERE id = :id",
            ['id' => $this->requestId],
        );

        $this->messageBus->dispatch(new ExpireBorrowRequests());

        self::assertSame('expired', $this->itemStatus($this->lighthouse));
        self::assertSame(0, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :asker AND type = 'BorrowRequestExpired'",
            ['asker' => $this->asker],
        ));
    }

    public function testRecordingIveGotItClosesAnAskStillWaiting(): void
    {
        $this->messageBus->dispatch(new BorrowPuzzleFromPlayer($this->asker, $this->garden, $this->owner));

        self::assertSame('lent', $this->itemStatus($this->garden));
        self::assertSame('waiting', $this->itemStatus($this->lighthouse));
    }

    public function testOnlyTheOwnerOpensTheChatOfARequest(): void
    {
        $this->expectException(BorrowRequestNotFound::class);

        // The asker cannot use the request to get around the owner's "direct messages off"
        $this->messageBus->dispatch(new StartConversation(
            initiatorId: $this->asker,
            recipientId: $this->owner,
            initialMessage: 'Hello',
            borrowRequestId: $this->requestId,
        ));
    }

    public function testASecondAnswerChangesNothing(): void
    {
        $this->answer([$this->itemId($this->lighthouse) => 'declined']);
        $this->answer([$this->itemId($this->lighthouse) => 'yes']);

        self::assertSame('declined', $this->itemStatus($this->lighthouse));
    }

    public function testOnlyTheOwnerMayAnswer(): void
    {
        $this->expectException(BorrowRequestNotFound::class);

        $this->messageBus->dispatch(new AnswerBorrowRequest($this->requestId, $this->asker, [$this->itemId($this->lighthouse) => 'yes']));
    }

    public function testTheAskerWithdrawsOnePuzzleOrAllOfThem(): void
    {
        $this->messageBus->dispatch(new WithdrawBorrowRequest($this->requestId, $this->asker, $this->itemId($this->garden)));

        self::assertSame('waiting', $this->itemStatus($this->lighthouse));
        self::assertSame('withdrawn', $this->itemStatus($this->garden));

        $this->messageBus->dispatch(new WithdrawBorrowRequest($this->requestId, $this->asker));

        self::assertSame('withdrawn', $this->itemStatus($this->lighthouse));
    }

    public function testOnlyTheAskerMayWithdraw(): void
    {
        $this->expectException(BorrowRequestNotFound::class);

        $this->messageBus->dispatch(new WithdrawBorrowRequest($this->requestId, $this->owner));
    }

    public function testAnOldRequestExpiresAndTheAskerIsTold(): void
    {
        $this->answer([$this->itemId($this->garden) => 'declined']);
        $this->connection->executeStatement(
            "UPDATE borrow_request SET expires_at = " . TestClock::SQL_NOW . " - INTERVAL '1 day' WHERE id = :id",
            ['id' => $this->requestId],
        );

        $expired = $this->messageBus->dispatch(new ExpireBorrowRequests())->last(HandledStamp::class)?->getResult();

        self::assertSame(1, $expired);
        self::assertSame('expired', $this->itemStatus($this->lighthouse));
        self::assertSame('declined', $this->itemStatus($this->garden), 'An answered puzzle keeps its answer');
        self::assertSame(1, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :asker AND type = 'BorrowRequestExpired'",
            ['asker' => $this->asker],
        ));

        self::assertSame(0, $this->messageBus->dispatch(new ExpireBorrowRequests())->last(HandledStamp::class)?->getResult());
    }

    public function testLendingThePuzzleToTheAskerClosesTheirAsk(): void
    {
        $this->answer([$this->itemId($this->lighthouse) => 'yes']);

        $this->messageBus->dispatch(new LendPuzzleToPlayer($this->owner, $this->lighthouse, $this->asker));

        self::assertSame('lent', $this->itemStatus($this->lighthouse));
        self::assertSame('waiting', $this->itemStatus($this->garden));
    }

    /**
     * @param array<string, string> $answers
     */
    private function answer(array $answers): void
    {
        $this->messageBus->dispatch(new AnswerBorrowRequest($this->requestId, $this->owner, $answers));
    }

    private function itemId(string $puzzleId): string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM borrow_request_item WHERE request_id = :request AND puzzle_id = :puzzle',
            ['request' => $this->requestId, 'puzzle' => $puzzleId],
        );
        self::assertIsString($id);

        return $id;
    }

    private function itemStatus(string $puzzleId): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM borrow_request_item WHERE request_id = :request AND puzzle_id = :puzzle',
            ['request' => $this->requestId, 'puzzle' => $puzzleId],
        );
        self::assertIsString($status);

        return $status;
    }
}
