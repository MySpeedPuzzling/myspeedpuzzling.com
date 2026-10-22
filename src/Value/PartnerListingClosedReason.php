<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a partner finder listing is no longer open (docs/features/partner-finder.md D7): the puzzler found their partner
 * or took it down, or it closed by itself - they left the event, the event is over, they are in a pair/team entry.
 */
enum PartnerListingClosedReason: string
{
    case Found = 'found';
    case Withdrawn = 'withdrawn';
    case NotGoing = 'not_going';
    case EventOver = 'event_over';
    case Entered = 'entered';
}
