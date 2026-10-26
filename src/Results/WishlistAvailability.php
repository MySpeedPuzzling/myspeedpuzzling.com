<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the wishlist page shows next to each puzzle (ResolveWishlistAvailability, docs/features/wishlist-availability.md):
 * marketplace offers for everybody; for the list's owner also the favorites who could lend it, their own open asks,
 * and the suggested "fewest people for the most puzzles".
 */
final readonly class WishlistAvailability
{
    /**
     * @param array<string, WishlistPuzzleOffers> $offers by puzzle id
     * @param array<string, list<BorrowCandidate>> $owners by puzzle id - empty unless it is the viewer's own list
     * @param array<string, list<AskedBorrow>> $asked by puzzle id - the viewer's open asks
     * @param list<string> $suggestedPuzzleIds the puzzles the suggested plan asks for
     */
    public function __construct(
        public bool $isOwnList,
        public bool $viewerHasCountry,
        public array $offers = [],
        public array $owners = [],
        public array $asked = [],
        public array $suggestedPuzzleIds = [],
        public int $suggestedPeople = 0,
    ) {
    }

    public function offersFor(string $puzzleId): null|WishlistPuzzleOffers
    {
        return $this->offers[$puzzleId] ?? null;
    }

    /**
     * @return list<BorrowCandidate>
     */
    public function ownersFor(string $puzzleId): array
    {
        return $this->owners[$puzzleId] ?? [];
    }

    /**
     * Owners who could lend it right now (not lent out)
     */
    public function lendableOwnersCount(string $puzzleId): int
    {
        return count(array_filter($this->ownersFor($puzzleId), static fn (BorrowCandidate $owner): bool => $owner->lentOut === false));
    }

    /**
     * @return list<AskedBorrow>
     */
    public function askedFor(string $puzzleId): array
    {
        return $this->asked[$puzzleId] ?? [];
    }

    public function puzzlesOnMarketplace(): int
    {
        return count($this->offers);
    }

    public function puzzlesShippedToViewer(): int
    {
        return count(array_filter($this->offers, static fn (WishlistPuzzleOffers $offers): bool => ($offers->shipsToViewer ?? 0) > 0));
    }

    public function canAskSomebody(): bool
    {
        return $this->suggestedPuzzleIds !== [];
    }
}
