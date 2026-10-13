<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a round stopwatch action changed nothing (RoundStopwatchRefused) - the organiser reads it as a flash, the stopwatch
 * actions' JSON answers carry the value as their `error` code.
 */
enum RoundStopwatchRefusal: string
{
    // "Start at …" without a moment that exists in the round's zone (empty, malformed, skipped or repeated by a
    // daylight-saving change) - refused by the controller before anything is dispatched
    case InvalidStart = 'invalid_start';
    // A scheduled start must lie ahead - its moment passed before the start was saved
    case StartInThePast = 'start_in_the_past';
    // A start is scheduled only for a clock that never ran (or to move a countdown) - running or stopped needs a reset
    case AlreadyStarted = 'already_started';
    // The countdown is over: the round runs, its start can no longer be called off
    case TooLateToCancel = 'too_late_to_cancel';
    // "Start" while a countdown runs would move the start every phone counts down to - cancel the countdown first
    case StartScheduled = 'start_scheduled';

    public function translationKey(): string
    {
        return 'competition.stopwatch.refused.' . $this->value;
    }
}
