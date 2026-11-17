<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a time proposal was decided the way it was, when it was not a plain confirmation:
 * - rejected by a judge (`field: "proposal"` change): not_finished, wrong_entry, other - not_finished also when the
 *   judge confirmed it with a result that is no finished time (pieces placed, did not start)
 * - superseded: result_recorded (a result recorded without it), stopwatch_reset (the round's stopwatch was reset)
 */
enum TimeProposalDecisionReason: string
{
    case NotFinished = 'not_finished';
    case WrongEntry = 'wrong_entry';
    case Other = 'other';
    case ResultRecorded = 'result_recorded';
    case StopwatchReset = 'stopwatch_reset';

    /**
     * The reasons a judge may give for a rejection
     */
    public static function tryFromJudge(mixed $value): null|self
    {
        $reason = is_string($value) ? self::tryFrom($value) : null;

        return in_array($reason, [self::NotFinished, self::WrongEntry, self::Other], true) ? $reason : null;
    }
}
