<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a listing of a "could I have these?" request was not asked for. Unavailable deliberately covers every reason that
 * must not be told apart (the listing left the list, a block either way, a secret puzzle) - the buyer can never learn
 * about a block from it.
 */
enum ListingAskSkipReason: string
{
    case Unavailable = 'unavailable';
    case AlreadyAsked = 'already_asked';
    case Reserved = 'reserved';
    case Own = 'own';
}
