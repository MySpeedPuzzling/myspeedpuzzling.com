<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ListingRequestItemStatus;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * One listing of a listing request and where it stands (docs/features/marketplace/12-listing-requests.md).
 *
 * The listing's type, price, currency and condition are copied when it is asked for: both sides see what was asked even
 * after the seller edits the listing, and the listing itself may go (sold, removed - the link becomes NULL, the item
 * stays as history and is closed by ListingRequestCloser). The buyer is copied from the request so that "one open ask
 * per buyer and listing" can be a unique index (custom_listing_request_item_one_open).
 */
#[Entity]
#[Index(name: 'listing_request_item_request', columns: ['request_id'])]
#[Index(name: 'listing_request_item_listing', columns: ['listing_id'])]
#[Index(name: 'listing_request_item_seller_puzzle', columns: ['seller_id', 'puzzle_id'])]
class ListingRequestItem
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, length: 20, enumType: ListingRequestItemStatus::class)]
    public ListingRequestItemStatus $status = ListingRequestItemStatus::Waiting;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $answeredAt = null;

    // Withdrawn, expired, sold or unavailable
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $closedAt = null;

    // The seller's yes reserved the listing for the buyer - only such a reservation is ever taken back by the request
    // (the buyer withdrawing); one the seller made by hand stays theirs
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $reservedByRequest = false;

    #[Immutable]
    #[ManyToOne]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Player $buyer;

    #[Immutable]
    #[ManyToOne]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Player $seller;

    // Changes only when the puzzle is merged into another one (puzzleMergedInto())
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Puzzle $puzzle;

    #[Immutable]
    #[Column(type: Types::STRING, enumType: ListingType::class)]
    public ListingType $listingType;

    #[Immutable]
    #[Column(type: Types::FLOAT, nullable: true)]
    public null|float $price;

    // What the seller's list shows next to prices (SellSwapListSettings: the custom currency, else the ISO code)
    #[Immutable]
    #[Column(length: 50, nullable: true)]
    public null|string $currency;

    #[Immutable]
    #[Column(type: Types::STRING, enumType: PuzzleCondition::class)]
    public PuzzleCondition $condition;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public ListingRequest $request,
        // NULL once the listing left the list (sold, removed) - the item stays as history
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|SellSwapListItem $listing,
    ) {
        if ($listing === null) {
            throw new \InvalidArgumentException('A listing request item is created for an existing listing.');
        }

        $this->buyer = $request->buyer;
        $this->seller = $request->seller;
        $this->puzzle = $listing->puzzle;
        $this->listingType = $listing->listingType;
        $this->price = $listing->price;
        $settings = $listing->player->sellSwapListSettings;
        $this->currency = $settings !== null ? mb_substr((string) ($settings->customCurrency ?? $settings->currency), 0, 50) : null;
        $this->currency = $this->currency === '' ? null : $this->currency;
        $this->condition = $listing->condition;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * The seller's answer. Only a waiting listing is answered - a second answer, or one after the buyer withdrew,
     * changes nothing (a resent answer form is harmless). True = it changed.
     */
    public function answer(ListingRequestItemStatus $answer, DateTimeImmutable $now, bool $reservedByRequest = false): bool
    {
        if ($answer->isAnswer() === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an answer to a listing request.', $answer->value));
        }

        if ($this->status !== ListingRequestItemStatus::Waiting) {
            return false;
        }

        $this->status = $answer;
        $this->answeredAt = $now;
        $this->reservedByRequest = $answer === ListingRequestItemStatus::Yes && $reservedByRequest;

        return true;
    }

    public function withdraw(DateTimeImmutable $now): bool
    {
        return $this->close(ListingRequestItemStatus::Withdrawn, $now);
    }

    /**
     * Only a waiting listing expires - a yes holds a reservation the seller manages.
     */
    public function expire(DateTimeImmutable $now): bool
    {
        if ($this->status !== ListingRequestItemStatus::Waiting) {
            return false;
        }

        return $this->close(ListingRequestItemStatus::Expired, $now);
    }

    /**
     * The listing was sold or swapped to this buyer - also while still waiting: the two agreed some other way.
     */
    public function markSold(DateTimeImmutable $now): bool
    {
        return $this->close(ListingRequestItemStatus::Sold, $now);
    }

    /**
     * The listing was sold to somebody else or left the list.
     */
    public function markUnavailable(DateTimeImmutable $now): bool
    {
        return $this->close(ListingRequestItemStatus::Unavailable, $now);
    }

    /**
     * The listing is about to be deleted (ListingRequestCloser): the item stays as history without it. The database
     * would set the link NULL itself, but an item loaded in the same unit of work must not point at a removed entity.
     */
    public function listingLeft(): void
    {
        $this->listing = null;
    }

    /**
     * The puzzle was merged into the survivor of a puzzle merge (ApprovePuzzleMergeRequestHandler).
     */
    public function puzzleMergedInto(Puzzle $survivor): void
    {
        $this->puzzle = $survivor;
    }

    private function close(ListingRequestItemStatus $status, DateTimeImmutable $now): bool
    {
        if ($this->isOpen() === false) {
            return false;
        }

        $this->status = $status;
        $this->closedAt = $now;

        return true;
    }
}
