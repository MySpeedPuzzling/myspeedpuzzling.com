<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What kept a result from being saved twice (result_duplicate_prevention, docs/features/duplicate-results.md):
 * a resent form or API call answered with the result already saved, a "same time already saved" warning shown
 * in the form, or a result saved anyway after that warning.
 */
enum DuplicatePreventionKind: string
{
    case ResendCaught = 'resend_caught';
    case WarningShown = 'warning_shown';
    case SavedAnyway = 'saved_anyway';
}
