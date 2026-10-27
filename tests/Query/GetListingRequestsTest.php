<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetListingRequests;
use SpeedPuzzling\Web\Tests\ListingRequestSeeding;
use SpeedPuzzling\Web\Value\ListingRequestItemStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sell/swap requests as their two sides see them (docs/features/marketplace/12-listing-requests.md).
 */
final class GetListingRequestsTest extends KernelTestCase
{
    use ListingRequestSeeding;

    public function testBothSidesSeeTheRequestAndNobodyElse(): void
    {
        self::bootKernel();
        $query = self::getContainer()->get(GetListingRequests::class);

        $buyer = $this->seedPlayer('Jana');
        $seller = $this->seedPlayer('Anna');
        $stranger = $this->seedPlayer('Karel');
        $listing = $this->seedListing($seller, $this->seedPuzzle(1000, 'Lighthouse'));
        $offered = $this->seedListing($buyer, $this->seedPuzzle(500, 'My swap'));
        $this->seedListingType($listing, 'both');

        $this->askFor($buyer, [$seller => [$listing]], offersBySeller: [$seller => [$offered]], notes: [$seller => 'Hello']);
        $requestId = $this->requestIdOf($buyer, $seller);

        $sent = $query->sentBy($buyer);
        self::assertCount(1, $sent);
        self::assertSame('Anna', $sent[0]->counterpartName);
        self::assertSame('Hello', $sent[0]->note);
        self::assertSame(ListingRequestItemStatus::Waiting, $sent[0]->items[0]->status);
        self::assertSame(['EUR' => 10.0], $sent[0]->openTotals());
        self::assertSame('My swap', $sent[0]->offers[0]->puzzleName);

        $received = $query->receivedBy($seller);
        self::assertCount(1, $received);
        self::assertSame('Jana', $received[0]->counterpartName);
        self::assertSame(1, $query->waitingCountFor($seller));

        self::assertNotNull($query->forViewer($requestId, $seller));
        self::assertNull($query->forViewer($requestId, $stranger));
    }
}
