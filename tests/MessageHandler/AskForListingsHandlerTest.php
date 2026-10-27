<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ListingRequestTooLarge;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Message\SendListingRequestEmail;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Tests\ListingRequestSeeding;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\ListingAskSkipReason;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * "Could I have these?" (docs/features/marketplace/12-listing-requests.md): one request per seller, everything checked
 * again in the handler, idempotent per send.
 */
final class AskForListingsHandlerTest extends KernelTestCase
{
    use ListingRequestSeeding;

    private Connection $connection;
    private string $buyer;
    private string $anna;
    private string $petr;
    private string $lighthouse;
    private string $garden;
    private string $meadow;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);

        $this->buyer = $this->seedPlayer('Jana');
        $this->anna = $this->seedPlayer('Anna');
        $this->petr = $this->seedPlayer('Petr');

        $this->lighthouse = $this->seedListing($this->anna, $this->seedPuzzle(1000, 'Lighthouse'));
        // Unpublished listings show on the seller's own list page and may be asked for there
        $this->garden = $this->seedListing($this->anna, $this->seedPuzzle(500, 'Night Garden'), published: false);
        $this->meadow = $this->seedListing($this->petr, $this->seedPuzzle(500, 'Meadow'));
    }

    public function testOneRequestPerSellerWithTheListingsAsAskedANotificationAndAnEmail(): void
    {
        $outcome = $this->askFor($this->buyer, [
            $this->anna => [$this->lighthouse, $this->garden],
            $this->petr => [$this->meadow],
        ], notes: [$this->anna => 'Could you ship to Brno?']);

        self::assertSame(2, $outcome->requestsSent);
        self::assertSame(3, $outcome->listingsAsked);
        self::assertSame([], $outcome->skipped);

        $requestId = $this->requestIdOf($this->buyer, $this->anna);
        self::assertSame('Could you ship to Brno?', $this->connection->fetchOne('SELECT note FROM listing_request WHERE id = :id', ['id' => $requestId]));

        $item = $this->connection->fetchAssociative(
            'SELECT status, listing_type, price, currency, condition, seller_id FROM listing_request_item WHERE id = :id',
            ['id' => $this->itemIdFor($requestId, $this->lighthouse)],
        );
        self::assertSame(['status' => 'waiting', 'listing_type' => 'sell', 'price' => 10.0, 'currency' => 'EUR', 'condition' => 'like_new', 'seller_id' => $this->anna], $item);

        $notifications = array_values(array_filter(
            self::getContainer()->get(GetNotifications::class)->forPlayer($this->anna, 10),
            static fn ($notification): bool => $notification->isListingRequestNotification(),
        ));
        self::assertCount(1, $notifications);
        self::assertSame($this->buyer, $notifications[0]->targetPlayerId);
        self::assertSame(2, $notifications[0]->listingItemsTotal);

        self::assertCount(2, $this->queuedEmails());
    }

    public function testAResentFormAsksNobodyTwice(): void
    {
        $sendId = Uuid::uuid7()->toString();

        $this->askFor($this->buyer, [$this->anna => [$this->lighthouse]], sendId: $sendId);
        $again = $this->askFor($this->buyer, [$this->anna => [$this->lighthouse]], sendId: $sendId);

        self::assertSame(1, $again->requestsSent);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM listing_request WHERE buyer_id = :buyer', ['buyer' => $this->buyer]));
    }

    public function testListingsThatCannotBeAskedForAreSkippedWithTheirReason(): void
    {
        $this->askFor($this->buyer, [$this->anna => [$this->lighthouse]]);

        $own = $this->seedListing($this->buyer, $this->seedPuzzle(500, 'My own'));
        $reservedForSomebodyElse = $this->seedListing($this->anna, $this->seedPuzzle(500, 'Taken'));
        $this->seedReservedFor($reservedForSomebodyElse, $this->petr);
        $reservedForBuyer = $this->seedListing($this->anna, $this->seedPuzzle(500, 'Mine already'));
        $this->seedReservedFor($reservedForBuyer, $this->buyer);
        $missing = Uuid::uuid7()->toString();

        $outcome = $this->askFor($this->buyer, [
            $this->anna => [$this->lighthouse, $reservedForSomebodyElse, $reservedForBuyer, $missing, $this->meadow],
            $this->buyer => [$own],
        ]);

        self::assertSame(1, $outcome->listingsAsked);
        self::assertSame([
            $this->lighthouse => ListingAskSkipReason::AlreadyAsked,
            $reservedForSomebodyElse => ListingAskSkipReason::Reserved,
            $missing => ListingAskSkipReason::Unavailable,
            // Petr's listing grouped under Anna
            $this->meadow => ListingAskSkipReason::Unavailable,
            $own => ListingAskSkipReason::Own,
        ], $outcome->skipped);
    }

    public function testABlockEitherWayLooksLikeAListingThatIsGoneAndSavesNothing(): void
    {
        $this->seedBlock($this->anna, $this->buyer);
        $this->seedBlock($this->buyer, $this->petr);

        $outcome = $this->askFor($this->buyer, [$this->anna => [$this->lighthouse], $this->petr => [$this->meadow]]);

        self::assertSame(0, $outcome->requestsSent);
        self::assertSame([$this->lighthouse => ListingAskSkipReason::Unavailable, $this->meadow => ListingAskSkipReason::Unavailable], $outcome->skipped);
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM listing_request WHERE buyer_id = :buyer', ['buyer' => $this->buyer]));
        self::assertSame([], $this->queuedEmails());
    }

    public function testOnlyTheBuyersOwnListingsAreOffered(): void
    {
        $this->seedListingType($this->lighthouse, 'swap');
        $mine = $this->seedListing($this->buyer, $this->seedPuzzle(500, 'Mine'));

        $this->askFor($this->buyer, [$this->anna => [$this->lighthouse]], offersBySeller: [$this->anna => [$mine, $this->meadow]]);

        self::assertSame(
            [$mine],
            $this->connection->fetchFirstColumn('SELECT listing_id FROM listing_request_offer WHERE request_id = :id', ['id' => $this->requestIdOf($this->buyer, $this->anna)]),
        );
    }

    public function testMoreSellersThanOneSendHoldsIsRefused(): void
    {
        $listingsBySeller = [];

        for ($i = 0; $i < 11; $i++) {
            $seller = $this->seedPlayer('Seller ' . $i);
            $listingsBySeller[$seller] = [$this->seedListing($seller, $this->seedPuzzle(500, 'P' . $i))];
        }

        $this->expectException(ListingRequestTooLarge::class);
        $this->askFor($this->buyer, $listingsBySeller);
    }

    public function testMoreListingsThanOneSendHoldsIsRefused(): void
    {
        $listings = [];

        for ($i = 0; $i < 31; $i++) {
            $listings[] = $this->seedListing($this->anna, $this->seedPuzzle(500, 'L' . $i));
        }

        $this->expectException(ListingRequestTooLarge::class);
        $this->askFor($this->buyer, [$this->anna => $listings]);
    }

    public function testABuyerWithPausedMessagingCannotAsk(): void
    {
        $this->connection->executeStatement(
            'UPDATE player SET messaging_muted = true, messaging_muted_until = ' . TestClock::SQL_NOW . " + INTERVAL '1 day' WHERE id = :id",
            ['id' => $this->buyer],
        );

        try {
            $this->askFor($this->buyer, [$this->anna => [$this->lighthouse]]);
            self::fail('A muted buyer must not ask');
        } catch (HandlerFailedException $exception) {
            self::assertNotSame([], $exception->getWrappedExceptions(MessagingMuted::class, true));
        }
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
            if ($envelope->getMessage() instanceof SendListingRequestEmail) {
                $ids[] = $envelope->getMessage()->requestId;
            }
        }

        return $ids;
    }
}
