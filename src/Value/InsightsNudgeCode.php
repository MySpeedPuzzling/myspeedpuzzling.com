<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The "Make your insights sharper" items (InsightsNudge): what the player can do so the page knows more about them.
 * The sentences are the page's translation keys (insights.nudges.<value>); the builder only says which apply.
 */
enum InsightsNudgeCode: string
{
    /** count = results still needed, target = the next threshold (InsightsReport::UNLOCK_THRESHOLDS) */
    case MoreResults = 'more_results';
    /** count = results that are the first on their puzzle but not marked as a first try */
    case MarkFirstTries = 'mark_first_tries';
    /** No time zone: the time of day of your results needs one */
    case SetTimezone = 'set_timezone';
    /** count = your puzzles without a difficulty yet */
    case UnratedPuzzles = 'unrated_puzzles';
    /** You never marked a result as solved without the box */
    case MarkUnboxed = 'mark_unboxed';
    /** Time predictions are switched off in your settings: no expected times */
    case PredictionsOff = 'predictions_off';
}
