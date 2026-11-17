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
    // A Reset of a round with online timing starts a fresh run - not while official results of it are recorded (they
    // belong to the run that was): the results desk clears them first
    case ResultsConfirmed = 'results_confirmed';
    // "Start" (now, in 10 s, at a set time) of a round with online timing that ended ("End now"): an online round has no
    // resume - participants kept solving on the other side of the world - so it runs again only from a Reset (a fresh
    // run, README §5.1). In person a stopped clock is a pause and Start resumes it.
    case EndedUseReset = 'ended_use_reset';
    // "Reveal the puzzles" ticked, but what the start would reveal is not what the page listed any more - another
    // puzzle, another scope, or the stopwatch was scheduled, moved or called off meanwhile (SecretRevealPreview::
    // stopwatchStartHash()). Nothing started: the page shows the list as it is now, unticked.
    case RevealChangedMeanwhile = 'reveal_changed_meanwhile';
    // "Reveal the puzzles" ticked on a round with nothing waiting for the stopwatch start (a stale page) - nothing started
    case RevealNotAllowed = 'reveal_not_allowed';

    public function translationKey(): string
    {
        // The competition toolkit's own texts (one block under its prefix, toolkit/R-review-and-plan.md §4.1)
        return match ($this) {
            self::RevealChangedMeanwhile, self::RevealNotAllowed => 'competition_toolkit.reveal_stopwatch.refused.' . $this->value,
            default => 'competition.stopwatch.refused.' . $this->value,
        };
    }
}
