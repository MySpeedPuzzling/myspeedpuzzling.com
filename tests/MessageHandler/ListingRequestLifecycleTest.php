<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\ListingRequestNotFound;
use SpeedPuzzling\Web\Message\AdminRemoveListing;
use SpeedPuzzling\Web\Message\ExpireListingRequests;
use SpeedPuzzling\Web\Message\MarkPuzzleAsSoldOrSwapped;
use SpeedPuzzling\Web\Message\MarkPuzzlesAsSoldOrSwapped;
use SpeedPuzzling\Web\Message\RemovePuzzleFromSellSwapList;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromSellSwapList;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Message\WithdrawListingRequest;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ListingRequestSeeding;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A sell/swap request from the seller's answer to the puzzle changing hands (docs/features/marketplace/12-listing-requests.md).
 */
final class ListingRequestLifecycleTest extends KernelTestCase
{
    use ListingRequestSeeding;

    private Connection $connection;
    private MessageBusInterface $bus;
    private string $buyer;
    private string $otherBuyer;
    private string $seller;
    private string $lighthousePuzzle;
    private string $lighthouse;
    private string $gardenPuzzle;
    private string $garden;
    private string $requestId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);

        $this->buyer = $this->seedPlayer('Jana', code: 'janabuyer');
        $this->otherBuyer = $this->seedPlayer('Karel');
        $this->seller = $this->seedPlayer('Anna');
        $this->lighthousePuzzle = $this->seedPuzzle(1000, 'Lighthouse');
        $this->lighthouse = $this->seedListing($this->seller, $this->lighthousePuzzle);
        $this->gardenPuzzle = $this->seedPuzzle(500, 'Night Garden');
        $this->garden = $this->seedListing($this->seller, $this->gardenPuzzle);

        // The buyer keeps direct messages off - a yes still opens the chat, they asked first
        $this->connection->executeStatement('UPDATE player SET allow_direct_messages = false WHERE id = :id', ['id' => $this->buyer]);

        $this->askFor($this->buyer, [$this->seller => [$this->lighthouse, $this->garden]]);
        $this->requestId = $this->requestIdOf($this->buyer, $this->seller);
    }

    public function testAYesReservesTheListingForTheBuyerTellsThemAndOpensAChat(): void
    {
        $lighthouseItem = $this->itemIdFor($this->requestId, $this->lighthouse);
        $gardenItem = $this->itemIdFor($this->requestId, $this->garden);

        $outcome = $this->answerRequest($this->requestId, $this->seller, [$lighthouseItem => 'yes', $gardenItem => 'declined']);

        self::assertSame(2, $outcome->changed);
        self::assertSame('yes', $this->itemStatus($lighthouseItem));
        self::assertSame('declined', $this->itemStatus($gardenItem));
        self::assertSame(
            ['reserved' => true, 'reserved_for_player_id' => $this->buyer],
            $this->connection->fetchAssociative('SELECT reserved, reserved_for_player_id FROM sell_swap_list_item WHERE id = :id', ['id' => $this->lighthouse]),
        );
        self::assertTrue((bool) $this->connection->fetchOne('SELECT reserved_by_request FROM listing_request_item WHERE id = :id', ['id' => $lighthouseItem]));

        self::assertSame(1, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :buyer AND type = 'ListingRequestAnswered'",
            ['buyer' => $this->buyer],
        ));
        self::assertSame(1, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM conversation WHERE initiator_id = :seller AND recipient_id = :buyer AND status = 'accepted'",
            ['seller' => $this->seller, 'buyer' => $this->buyer],
        ));
        self::assertNotNull($this->connection->fetchOne('SELECT replied_at FROM listing_request WHERE id = :id', ['id' => $this->requestId]));
    }

    public function testAYesForAListingReservedForSomebodyElseIsRefusedAndKeepsWaiting(): void
    {
        $this->seedReservedFor($this->lighthouse, $this->otherBuyer);
        $item = $this->itemIdFor($this->requestId, $this->lighthouse);

        $outcome = $this->answerRequest($this->requestId, $this->seller, [$item => 'yes']);

        self::assertSame(0, $outcome->changed);
        self::assertSame(1, $outcome->reservedForSomebodyElse);
        self::assertSame('waiting', $this->itemStatus($item));
        self::assertSame($this->otherBuyer, $this->connection->fetchOne('SELECT reserved_for_player_id FROM sell_swap_list_item WHERE id = :id', ['id' => $this->lighthouse]));
    }

    public function testOnlyTheSellerAnswers(): void
    {
        $this->expectException(ListingRequestNotFound::class);
        $this->answerRequest($this->requestId, $this->buyer, [$this->itemIdFor($this->requestId, $this->lighthouse) => 'yes']);
    }

    public function testAnswerOnlyChangesWaitingListings(): void
    {
        $item = $this->itemIdFor($this->requestId, $this->lighthouse);
        $this->answerRequest($this->requestId, $this->seller, [$item => 'declined']);

        $again = $this->answerRequest($this->requestId, $this->seller, [$item => 'yes']);

        self::assertSame(0, $again->changed);
        self::assertSame('declined', $this->itemStatus($item));
    }

    public function testGoneTakesTheListingOffTheListAndEveryOtherAskOfItBecomesUnavailable(): void
    {
        $this->askFor($this->otherBuyer, [$this->seller => [$this->lighthouse]]);
        $otherItem = $this->itemIdFor($this->requestIdOf($this->otherBuyer, $this->seller), $this->lighthouse);
        $item = $this->itemIdFor($this->requestId, $this->lighthouse);

        $this->answerRequest($this->requestId, $this->seller, [$item => 'gone'], removeFromList: [$item]);

        self::assertSame('gone', $this->itemStatus($item));
        self::assertSame('unavailable', $this->itemStatus($otherItem));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM sell_swap_list_item WHERE id = :id', ['id' => $this->lighthouse]));
        self::assertSame(1, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :buyer AND type = 'ListingRequestUnavailable'",
            ['buyer' => $this->otherBuyer],
        ));
        // The item outlives its listing, as history
        self::assertNull($this->connection->fetchOne('SELECT listing_id FROM listing_request_item WHERE id = :id', ['id' => $item]));
    }

    public function testSoldToTheBuyerClosesTheirAskAsSoldAndEverybodyElsesAsUnavailable(): void
    {
        $this->askFor($this->otherBuyer, [$this->seller => [$this->lighthouse]]);
        $otherItem = $this->itemIdFor($this->requestIdOf($this->otherBuyer, $this->seller), $this->lighthouse);
        $item = $this->itemIdFor($this->requestId, $this->lighthouse);
        $this->answerRequest($this->requestId, $this->seller, [$item => 'yes']);

        $this->bus->dispatch(new MarkPuzzleAsSoldOrSwapped($this->lighthouse, $this->seller, '#janabuyer'));

        self::assertSame('sold', $this->itemStatus($item));
        self::assertSame('unavailable', $this->itemStatus($otherItem));
        self::assertSame(0, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :buyer AND type = 'ListingRequestUnavailable'",
            ['buyer' => $this->buyer],
        ));
    }

    public function testBulkSoldWithNobodyNamedGoesToTheBuyerItWasReservedFor(): void
    {
        $this->askFor($this->otherBuyer, [$this->seller => [$this->lighthouse]]);
        $otherItem = $this->itemIdFor($this->requestIdOf($this->otherBuyer, $this->seller), $this->lighthouse);
        $item = $this->itemIdFor($this->requestId, $this->lighthouse);
        $gardenItem = $this->itemIdFor($this->requestId, $this->garden);
        $this->answerRequest($this->requestId, $this->seller, [$item => 'yes']);

        $this->bus->dispatch(new MarkPuzzlesAsSoldOrSwapped($this->seller, [$this->lighthousePuzzle, $this->gardenPuzzle]));

        self::assertSame('sold', $this->itemStatus($item));
        self::assertSame('unavailable', $this->itemStatus($otherItem));
        self::assertSame('unavailable', $this->itemStatus($gardenItem));
    }

    public function testBulkRemoveAndAdminRemoveCloseTheAsks(): void
    {
        $lighthouseItem = $this->itemIdFor($this->requestId, $this->lighthouse);
        $gardenItem = $this->itemIdFor($this->requestId, $this->garden);

        $this->bus->dispatch(new RemovePuzzlesFromSellSwapList($this->seller, [$this->lighthousePuzzle]));
        $this->bus->dispatch(new AdminRemoveListing($this->garden, PlayerFixture::PLAYER_ADMIN));

        self::assertSame('unavailable', $this->itemStatus($lighthouseItem));
        self::assertSame('unavailable', $this->itemStatus($gardenItem));
        // Two separate removals - the buyer hears about each
        self::assertSame(2, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :buyer AND type = 'ListingRequestUnavailable'",
            ['buyer' => $this->buyer],
        ));
    }

    public function testOnlyTheSellerOpensAChatFromTheRequest(): void
    {
        $this->expectException(ListingRequestNotFound::class);

        try {
            $this->bus->dispatch(new StartConversation(
                initiatorId: $this->otherBuyer,
                recipientId: $this->buyer,
                initialMessage: 'Hi',
                listingRequestId: $this->requestId,
            ));
        } catch (HandlerFailedException $exception) {
            throw $exception->getWrappedExceptions(ListingRequestNotFound::class, true)[0] ?? $exception;
        }
    }

    public function testRemovedFromTheListWhileWaitingTellsTheBuyer(): void
    {
        $item = $this->itemIdFor($this->requestId, $this->lighthouse);

        $this->bus->dispatch(new RemovePuzzleFromSellSwapList($this->seller, $this->lighthousePuzzle));

        self::assertSame('unavailable', $this->itemStatus($item));
        self::assertSame(1, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :buyer AND type = 'ListingRequestUnavailable'",
            ['buyer' => $this->buyer],
        ));
    }

    public function testWithdrawingAYesReleasesOnlyTheReservationTheRequestMade(): void
    {
        $lighthouseItem = $this->itemIdFor($this->requestId, $this->lighthouse);
        $gardenItem = $this->itemIdFor($this->requestId, $this->garden);
        // Reserved for the buyer by hand before the answer - the request never takes that one back
        $this->seedReservedFor($this->garden, $this->buyer);
        $this->answerRequest($this->requestId, $this->seller, [$lighthouseItem => 'yes', $gardenItem => 'yes']);

        $this->bus->dispatch(new WithdrawListingRequest($this->requestId, $this->buyer));

        self::assertSame('withdrawn', $this->itemStatus($lighthouseItem));
        self::assertSame('withdrawn', $this->itemStatus($gardenItem));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT reserved FROM sell_swap_list_item WHERE id = :id', ['id' => $this->lighthouse]));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT reserved FROM sell_swap_list_item WHERE id = :id', ['id' => $this->garden]));
    }

    public function testOnlyTheBuyerWithdraws(): void
    {
        $this->expectException(ListingRequestNotFound::class);
        $this->bus->dispatch(new WithdrawListingRequest($this->requestId, $this->seller));
    }

    public function testOnlyWaitingListingsExpireAndTheBuyerIsTold(): void
    {
        $lighthouseItem = $this->itemIdFor($this->requestId, $this->lighthouse);
        $gardenItem = $this->itemIdFor($this->requestId, $this->garden);
        $this->answerRequest($this->requestId, $this->seller, [$lighthouseItem => 'yes']);
        $this->connection->executeStatement(
            'UPDATE listing_request SET expires_at = ' . TestClock::SQL_NOW . " - INTERVAL '1 day' WHERE id = :id",
            ['id' => $this->requestId],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $expired = $this->bus->dispatch(new ExpireListingRequests())->last(HandledStamp::class)?->getResult();

        self::assertSame(1, $expired);
        self::assertSame('yes', $this->itemStatus($lighthouseItem));
        self::assertSame('expired', $this->itemStatus($gardenItem));
        self::assertSame(1, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :buyer AND type = 'ListingRequestExpired'",
            ['buyer' => $this->buyer],
        ));
    }

    public function testAnAskWhoseListingWentAroundTheCloserIsClosedByTheDailyRun(): void
    {
        $item = $this->itemIdFor($this->requestId, $this->lighthouse);
        $this->connection->executeStatement('DELETE FROM sell_swap_list_item WHERE id = :id', ['id' => $this->lighthouse]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->bus->dispatch(new ExpireListingRequests());

        self::assertSame('unavailable', $this->itemStatus($item));
    }

    public function testABlockSinceTheRequestKeepsTheAnswersButTellsNobody(): void
    {
        $this->seedBlock($this->buyer, $this->seller);
        $item = $this->itemIdFor($this->requestId, $this->lighthouse);

        $this->answerRequest($this->requestId, $this->seller, [$item => 'declined']);

        self::assertSame('declined', $this->itemStatus($item));
        self::assertSame(0, $this->connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE player_id = :buyer AND type = 'ListingRequestAnswered'",
            ['buyer' => $this->buyer],
        ));
    }
}
