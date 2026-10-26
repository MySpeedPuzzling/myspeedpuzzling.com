<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a puzzle of an "ask to borrow" was not asked for. Unavailable deliberately covers every reason that must not be
 * told apart (the owner is no longer a favorite, became private, a block either way, no longer has it in a public
 * collection, a secret puzzle) - the asker can never learn about a block from it.
 */
enum BorrowAskSkipReason: string
{
    case Unavailable = 'unavailable';
    case AlreadyAsked = 'already_asked';
    case LentOut = 'lent_out';
}
