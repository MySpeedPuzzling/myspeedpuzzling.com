<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a planned contact was not sent - checked again at send time, the planning can be days old.
 */
enum ResultReviewContactSkipReason: string
{
    // The player switched result_emails_enabled off meanwhile
    case SwitchedOff = 'switched_off';
    // No e-mail address to send to
    case NoEmail = 'no_email';
    // Everything was resolved or undone meanwhile (a Tier C case alone does not count)
    case NothingLeft = 'nothing_left';
}
