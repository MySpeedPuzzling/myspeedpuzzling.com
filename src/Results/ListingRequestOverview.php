<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ListingRequestItemStatus;

/**
 * A listing request as one of its two sides sees it (GetListingRequests): the other person (buyer for the seller, seller
 * for the buyer), the listings with their status and the buyer's puzzles offered in exchange. A private counterpart is
 * named - asking was the buyer's consent, and a seller's listings are public offers - but never shown by their picture.
 */
final readonly class ListingRequestOverview
{
    /**
     * @param list<ListingRequestItemOverview> $items
     * @param list<ListingRequestOfferOverview> $offers
     */
    public function __construct(
        public string $requestId,
        public string $buyerId,
        public string $sellerId,
        public string $counterpartId,
        public null|string $counterpartName,
        public string $counterpartCode,
        public null|string $counterpartAvatar,
        public bool $counterpartIsPrivate,
        public null|string $note,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public bool $replied,
        public array $items,
        public array $offers,
    ) {
    }

    public function counterpartDisplayName(): string
    {
        return $this->counterpartName ?? '#' . strtoupper($this->counterpartCode);
    }

    public function countWithStatus(ListingRequestItemStatus ...$statuses): int
    {
        return count(array_filter($this->items, static fn (ListingRequestItemOverview $item): bool => in_array($item->status, $statuses, true)));
    }

    public function isWaiting(): bool
    {
        return $this->countWithStatus(ListingRequestItemStatus::Waiting) > 0;
    }

    public function isOpen(): bool
    {
        return $this->countWithStatus(ListingRequestItemStatus::Waiting, ListingRequestItemStatus::Yes) > 0;
    }

    /**
     * Prices of the waiting and agreed listings that have one, summed per currency - never across currencies.
     *
     * @return array<string, float> currency label ('' = none set) => sum
     */
    public function openTotals(): array
    {
        $totals = [];

        foreach ($this->items as $item) {
            if ($item->status->isOpen() && $item->hasPrice()) {
                $key = $item->currency ?? '';
                $totals[$key] = ($totals[$key] ?? 0.0) + (float) $item->price;
            }
        }

        return $totals;
    }
}
