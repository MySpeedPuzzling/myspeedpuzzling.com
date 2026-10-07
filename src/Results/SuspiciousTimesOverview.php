<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The "Numbers" tab of the time verification queue (docs/features/suspicious-time-review.md, "Moderator queue"):
 * what the detector raised and what became of it - what the next threshold change is judged by.
 */
readonly final class SuspiciousTimesOverview
{
    /**
     * @param list<array{version: null|int, direction: null|string, raised: int, pending: int, marked: int, trusted: int, corrected: int, gone: int}> $byVersion
     *        version null = flagged outside the app and never raised by the scan
     * @param list<array{code: string, trigger: bool, shown_to_player: bool, raised: int, pending: int, marked: int, trusted: int, precision: null|int}> $byReason
     *        marked = marked or corrected (the mark was right), precision = marked of the decided, in percent
     * @param list<array{via: string, notices: int, fixed: int, says_correct: int, left_as_is: int, no_reaction: int, answered_trusted: int, answered_kept: int}> $players
     * @param array<string, int> $decisions decision kind => rows of the decision log
     */
    public function __construct(
        public array $byVersion,
        public array $byReason,
        public array $players,
        public array $decisions,
    ) {
    }
}
