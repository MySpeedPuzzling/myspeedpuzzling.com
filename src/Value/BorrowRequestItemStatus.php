<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where one puzzle of an "ask to borrow" stands (docs/features/wishlist-availability.md). Waiting = nobody answered
 * yet; the owner's answers are yes / not_owned / declined; withdrawn and expired close it from the asker's side or by
 * time; lent = the puzzle changed hands through the lend/borrow flow.
 */
enum BorrowRequestItemStatus: string
{
    case Waiting = 'waiting';
    case Yes = 'yes';
    case NotOwned = 'not_owned';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Lent = 'lent';

    /**
     * Still something to do about it: the owner has to answer (waiting), or the box has to change hands (yes).
     */
    public function isOpen(): bool
    {
        return $this === self::Waiting || $this === self::Yes;
    }

    /**
     * What the owner may answer.
     */
    public function isAnswer(): bool
    {
        return $this === self::Yes || $this === self::NotOwned || $this === self::Declined;
    }
}
