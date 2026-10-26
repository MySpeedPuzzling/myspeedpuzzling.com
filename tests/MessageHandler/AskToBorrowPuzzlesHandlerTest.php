<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\BorrowRequestLimitReached;
use SpeedPuzzling\Web\Exceptions\BorrowRequestTooLarge;
use SpeedPuzzling\Web\Message\AskToBorrowPuzzles;
use SpeedPuzzling\Web\Message\SendBorrowRequestEmail;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Value\BorrowAskOutcome;
use SpeedPuzzling\Web\Value\BorrowAskSkipReason;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "Could I borrow these?" (docs/features/wishlist-availability.md): one request per owner, checked again in the
 * handler, idempotent per send.
 */
final class AskToBorrowPuzzlesHandlerTest extends KernelTestCase
{
    use BorrowSeeding;

    private Connection $connection;
    private string $asker;
    private string $anna;
    private string $petr;
    private string $lighthouse;
    private string $garden;
    private string $meadow;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);

        $this->asker = $this->seedPlayer('Jana');
        $this->anna = $this->seedPlayer('Anna');
        $this->petr = $this->seedPlayer('Petr');
        $this->lighthouse = $this->seedPuzzle(1000, 'Lighthouse');
        $this->garden = $this->seedPuzzle(500, 'Night Garden');
        $this->meadow = $this->seedPuzzle(500, 'Meadow');

        foreach ([$this->anna, $this->petr] as $owner) {
            $this->seedFavorite($this->asker, $owner);
            $this->seedCollectionVisibility($owner, true);
        }

        $this->seedOwned($this->anna, $this->lighthouse);
        $this->seedOwned($this->anna, $this->garden);
        $this->seedOwned($this->petr, $this->meadow);

        foreach ([$this->lighthouse, $this->garden, $this->meadow] as $puzzle) {
            $this->seedWished($this->asker, $puzzle);
        }
    }

    public function testOneRequestPerOwnerWithANotificationAndAnEmail(): void
    {
        $outcome = $this->ask([
            $this->anna => [$this->lighthouse, $this->garden],
            $this->petr => [$this->meadow],
        ], [$this->anna => 'Pick up on Saturday?']);

        self::assertSame(2, $outcome->requestsSent);
        self::assertSame(3, $outcome->puzzlesAsked);
        self::assertSame([], $outcome->skipped);

        $requests = $this->connection->fetchAllAssociative(
            'SELECT br.id, br.owner_id, br.note, COUNT(item.id) AS items FROM borrow_request br JOIN borrow_request_item item ON item.request_id = br.id WHERE br.asker_id = :asker GROUP BY br.id ORDER BY items DESC',
            ['asker' => $this->asker],
        );
        self::assertCount(2, $requests);
        self::assertSame($this->anna, $requests[0]['owner_id']);
        self::assertSame('Pick up on Saturday?', $requests[0]['note']);
        self::assertSame(2, $requests[0]['items']);

        self::assertSame(
            ['waiting'],
            $this->connection->fetchFirstColumn('SELECT DISTINCT status FROM borrow_request_item WHERE asker_id = :asker', ['asker' => $this->asker]),
        );

        $notifications = array_values(array_filter(
            self::getContainer()->get(GetNotifications::class)->forPlayer($this->anna, 10),
            static fn ($notification): bool => $notification->isBorrowRequestNotification(),
        ));
        self::assertCount(1, $notifications);
        self::assertSame($this->asker, $notifications[0]->targetPlayerId);
        self::assertSame(2, $notifications[0]->borrowItemsTotal);

        self::assertCount(2, $this->queuedEmails());
    }

    public function testAResentFormAsksNobodyTwice(): void
    {
        $sendId = Uuid::uuid7()->toString();

        $this->ask([$this->anna => [$this->lighthouse]], sendId: $sendId);
        $again = $this->ask([$this->anna => [$this->lighthouse]], sendId: $sendId);

        self::assertSame(1, $again->requestsSent);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM borrow_request WHERE asker_id = :asker', ['asker' => $this->asker]));
    }

    public function testPuzzlesThatCannotBeAskedForAreSkippedWithTheirReason(): void
    {
        $this->ask([$this->anna => [$this->lighthouse]]);

        $this->seedLentOut($this->anna, $this->garden);

        $outcome = $this->ask([
            // Already waiting / lent out / Anna does not have it
            $this->anna => [$this->lighthouse, $this->garden, $this->meadow],
        ]);

        self::assertSame(0, $outcome->requestsSent);
        self::assertSame([
            $this->lighthouse => BorrowAskSkipReason::AlreadyAsked,
            $this->garden => BorrowAskSkipReason::LentOut,
            $this->meadow => BorrowAskSkipReason::Unavailable,
        ], $outcome->skipped);
    }

    public function testABlockLooksLikeAPuzzleThatIsNotAvailableAndSavesNothing(): void
    {
        $this->seedBlock($this->anna, $this->asker);

        $outcome = $this->ask([$this->anna => [$this->lighthouse]]);

        self::assertSame([$this->lighthouse => BorrowAskSkipReason::Unavailable], $outcome->skipped);
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM borrow_request WHERE asker_id = :asker', ['asker' => $this->asker]));
        self::assertSame([], $this->queuedEmails());
    }

    public function testAPuzzleIsAskedOfOneOwnerOnly(): void
    {
        $this->seedOwned($this->petr, $this->lighthouse);

        $outcome = $this->ask([
            $this->anna => [$this->lighthouse],
            $this->petr => [$this->lighthouse, $this->meadow],
        ]);

        self::assertSame(2, $outcome->puzzlesAsked);
        self::assertSame(
            [$this->meadow],
            $this->connection->fetchFirstColumn('SELECT puzzle_id FROM borrow_request_item WHERE owner_id = :petr', ['petr' => $this->petr]),
        );
    }

    public function testTheRateLimitStopsASend(): void
    {
        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = self::getContainer()->get('limiter.borrow_request');
        $limiter->create($this->asker)->consume($limiter->create($this->asker)->consume(0)->getRemainingTokens());

        try {
            $this->ask([$this->anna => [$this->lighthouse]]);
            self::fail('Over the limit the send must be refused');
        } catch (BorrowRequestLimitReached) {
        }

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM borrow_request WHERE asker_id = :asker', ['asker' => $this->asker]));
    }

    public function testMoreThanTenPeopleAtOnceIsRefused(): void
    {
        $puzzlesByOwner = [];

        for ($i = 0; $i <= AskToBorrowPuzzles::MAX_OWNERS; $i++) {
            $puzzlesByOwner[Uuid::uuid7()->toString()] = [Uuid::uuid7()->toString()];
        }

        $this->expectException(BorrowRequestTooLarge::class);

        $this->ask($puzzlesByOwner);
    }

    /**
     * @param array<string, list<string>> $puzzlesByOwner
     * @param array<string, string> $notes
     */
    private function ask(array $puzzlesByOwner, array $notes = [], null|string $sendId = null): BorrowAskOutcome
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new AskToBorrowPuzzles(
            $sendId ?? Uuid::uuid7()->toString(),
            $this->asker,
            $puzzlesByOwner,
            $notes,
        ));

        $outcome = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(BorrowAskOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * @return list<string>
     */
    private function queuedEmails(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $ids = [];

        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SendBorrowRequestEmail) {
                $ids[] = $envelope->getMessage()->requestId;
            }
        }

        return $ids;
    }
}
