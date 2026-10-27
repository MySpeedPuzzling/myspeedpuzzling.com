<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AnswerListingRequest;
use SpeedPuzzling\Web\Message\AskForListings;
use SpeedPuzzling\Web\Value\ListingAskOutcome;
use SpeedPuzzling\Web\Value\ListingRequestAnswerOutcome;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Sellers, listings and sends for the sell/swap request tests (docs/features/marketplace/12-listing-requests.md) - on
 * top of BorrowSeeding's players, puzzles and listings (a listing is "sell", 10 EUR, like new). DAMA rolls everything
 * back with the test.
 */
trait ListingRequestSeeding
{
    use BorrowSeeding;

    protected function seedListingType(string $listingId, string $type): void
    {
        $this->borrowDatabase()->executeStatement('UPDATE sell_swap_list_item SET listing_type = :type WHERE id = :id', ['id' => $listingId, 'type' => $type]);
    }

    protected function seedReservedFor(string $listingId, null|string $playerId): void
    {
        $this->borrowDatabase()->executeStatement(
            'UPDATE sell_swap_list_item SET reserved = true, reserved_at = ' . TestClock::SQL_NOW . ', reserved_for_player_id = :playerId WHERE id = :id',
            ['id' => $listingId, 'playerId' => $playerId],
        );
        // Written past Doctrine - a listing a handler loaded before must be read again
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param array<string, list<string>> $listingsBySeller
     * @param array<string, list<string>> $offersBySeller
     * @param array<string, null|string> $notes
     */
    protected function askFor(string $buyerId, array $listingsBySeller, array $offersBySeller = [], array $notes = [], null|string $sendId = null): ListingAskOutcome
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new AskForListings(
            $sendId ?? Uuid::uuid7()->toString(),
            $buyerId,
            $listingsBySeller,
            $offersBySeller,
            $notes,
        ));

        $outcome = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ListingAskOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * @param array<string, string> $answers item id => status
     * @param list<string> $removeFromList
     */
    protected function answerRequest(string $requestId, string $sellerId, array $answers, array $removeFromList = [], bool $openChat = false): ListingRequestAnswerOutcome
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new AnswerListingRequest(
            $requestId,
            $sellerId,
            $answers,
            $removeFromList,
            $openChat,
            'en',
        ));

        $outcome = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ListingRequestAnswerOutcome::class, $outcome);

        return $outcome;
    }

    protected function requestIdOf(string $buyerId, string $sellerId): string
    {
        $id = $this->borrowDatabase()->fetchOne(
            'SELECT id FROM listing_request WHERE buyer_id = :buyer AND seller_id = :seller ORDER BY created_at DESC, id DESC LIMIT 1',
            ['buyer' => $buyerId, 'seller' => $sellerId],
        );
        self::assertIsString($id);

        return $id;
    }

    protected function itemIdFor(string $requestId, string $listingId): string
    {
        $id = $this->borrowDatabase()->fetchOne(
            'SELECT id FROM listing_request_item WHERE request_id = :request AND listing_id = :listing',
            ['request' => $requestId, 'listing' => $listingId],
        );
        self::assertIsString($id);

        return $id;
    }

    protected function itemStatus(string $itemId): string
    {
        $status = $this->borrowDatabase()->fetchOne('SELECT status FROM listing_request_item WHERE id = :id', ['id' => $itemId]);
        self::assertIsString($status);

        return $status;
    }
}
