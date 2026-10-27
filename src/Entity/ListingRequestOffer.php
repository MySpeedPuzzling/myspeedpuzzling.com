<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

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
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * One puzzle of the buyer's own sell/swap list offered in exchange in a listing request
 * (docs/features/marketplace/12-listing-requests.md). Only shown to the seller - it reserves nothing on the buyer's side.
 * Copied like a ListingRequestItem: the buyer's listing may change or go, the offer stays what was offered.
 */
#[Entity]
#[Index(name: 'listing_request_offer_request', columns: ['request_id'])]
class ListingRequestOffer
{
    // Changes only when the puzzle is merged into another one (puzzleMergedInto())
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Puzzle $puzzle;

    #[Immutable]
    #[Column(type: Types::STRING, enumType: ListingType::class)]
    public ListingType $listingType;

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
        // NULL once the buyer's listing left their list (listingLeft())
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|SellSwapListItem $listing,
    ) {
        if ($listing === null) {
            throw new \InvalidArgumentException('An offer is created for an existing listing.');
        }

        $this->puzzle = $listing->puzzle;
        $this->listingType = $listing->listingType;
        $this->condition = $listing->condition;
    }

    /**
     * The buyer's listing is about to be deleted (ListingRequestCloser) - see ListingRequestItem::listingLeft().
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
}
