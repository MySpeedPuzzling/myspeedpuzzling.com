<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum SuspiciousTimeDecisionKind: string
{
    case Marked = 'marked';
    case Trusted = 'trusted';
    case Unmarked = 'unmarked';
    case KeptAfterReply = 'kept_after_reply';
    case CorrectedAutomatically = 'corrected_automatically';
    // puzzle_solving_time.suspicious was changed by SQL - the scan's reconciliation noticed it
    case MarkedOutsideApp = 'marked_outside_app';
    case UnmarkedOutsideApp = 'unmarked_outside_app';
    // "The piece count is right" on a puzzle card
    case PiecesConfirmed = 'pieces_confirmed';
}
