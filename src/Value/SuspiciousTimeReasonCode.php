<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a time was raised and what may explain it (docs/features/suspicious-time-review.md, "Triggers" and
 * "Explanations"). The player reads a reason as `suspicious_time.reason.<value>` (templates/suspicious_time/_reason.html.twig)
 * with the numbers of its params.
 */
enum SuspiciousTimeReasonCode: string
{
    // Triggers - every raised time has exactly one, first
    case FasterThanPredicted = 'faster_than_predicted';
    case FasterThanUsual = 'faster_than_usual';
    case BeyondKnownPace = 'beyond_known_pace';
    case SlowerThanPredicted = 'slower_than_predicted';
    case SlowerThanUsual = 'slower_than_usual';
    case BelowSlowFloor = 'below_slow_floor';

    // Explanations of a fast time - computed for raised times only
    case HoursLeftOut = 'hours_left_out';
    case TeammatesSavedGroup = 'teammates_saved_group';
    case CommentMentionsGroup = 'comment_mentions_group';
    case OftenInGroup = 'often_in_group';
    case OtherEdition = 'other_edition';
    case FastestOnPuzzle = 'fastest_on_puzzle';

    // Explanations of a slow time
    case MinutesInHoursBox = 'minutes_in_hours_box';
    case IncludesBreaks = 'includes_breaks';

    // Hints for either direction
    case NewPlayer = 'new_player';
    // The player answered "Yes, it's right" in the add/edit form
    case ConfirmedWhileSaving = 'confirmed_while_saving';
    // The stored prediction came from an earlier attempt that is itself far too slow - judged against the baseline
    // or pace instead
    case PredictionFromSlowAttempt = 'prediction_from_slow_attempt';

    /**
     * Hints for the moderator only - a player never reads them.
     */
    public function isShownToPlayer(): bool
    {
        return match ($this) {
            self::OftenInGroup, self::FastestOnPuzzle, self::NewPlayer, self::ConfirmedWhileSaving, self::PredictionFromSlowAttempt => false,
            default => true,
        };
    }

    public function isTrigger(): bool
    {
        return $this->direction() !== null;
    }

    /**
     * The direction of a trigger, null for an explanation.
     */
    public function direction(): null|SuspicionDirection
    {
        return match ($this) {
            self::FasterThanPredicted, self::FasterThanUsual, self::BeyondKnownPace => SuspicionDirection::Fast,
            self::SlowerThanPredicted, self::SlowerThanUsual, self::BelowSlowFloor => SuspicionDirection::Slow,
            default => null,
        };
    }

    /**
     * A likely mistake with a fix to offer - it makes a raised time strong whatever its ratio.
     */
    public function isFittingExplanation(): bool
    {
        return match ($this) {
            self::HoursLeftOut, self::TeammatesSavedGroup, self::CommentMentionsGroup, self::OtherEdition,
            self::MinutesInHoursBox, self::IncludesBreaks => true,
            default => false,
        };
    }
}
