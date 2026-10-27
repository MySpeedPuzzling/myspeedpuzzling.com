<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where one listing of a "could I have these?" request stands (docs/features/marketplace/12-listing-requests.md).
 * Waiting = the seller has not answered; yes = the seller agreed and the listing is reserved for the buyer; gone /
 * declined = the seller's other answers; withdrawn and expired close it from the buyer's side or by time; sold = it was
 * sold or swapped to this buyer; unavailable = the listing was sold to somebody else or left the list while open.
 */
enum ListingRequestItemStatus: string
{
    case Waiting = 'waiting';
    case Yes = 'yes';
    case Gone = 'gone';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Sold = 'sold';
    case Unavailable = 'unavailable';

    /**
     * Still something to do about it: the seller has to answer (waiting), or the puzzle has to change hands (yes).
     */
    public function isOpen(): bool
    {
        return $this === self::Waiting || $this === self::Yes;
    }

    /**
     * What the seller may answer.
     */
    public function isAnswer(): bool
    {
        return $this === self::Yes || $this === self::Gone || $this === self::Declined;
    }
}
