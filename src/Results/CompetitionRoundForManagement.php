<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class CompetitionRoundForManagement
{
    /**
     * @param list<string> $secretRevealModes
     */
    public function __construct(
        public string $id,
        public string $name,
        // null = no limit given (CompetitionRound::$minutesLimit)
        public null|int $minutesLimit,
        public DateTimeImmutable $startsAt,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        // What the round is shown in on the event pages - see RoundBadgeColor (organiser's colour or palette)
        public string $color,
        public string $textColor,
        // Index in the event's schedule (by start, ties by id) - decides the automatic colour
        public int $schedulePosition,
        public int $puzzleCount,
        public RoundCategory $category = RoundCategory::Solo,
        // The zone startsAt is shown in - see RoundTimezone
        public string $timezone = RoundTimezone::FALLBACK,
        // Nobody said where the event is - $timezone is only the fallback, named without a place (timezone_name())
        public bool $timezoneAssumed = false,
        // How its puzzles are solved and K for `pick` (competition toolkit A2)
        public RoundPuzzleFormat $puzzleFormat = RoundPuzzleFormat::Total,
        public null|int $puzzlesToSolve = null,
        public int $revealDelayMinutes = RoundPuzzleReveal::DEFAULT_DELAY_MINUTES,
        // The reveal modes (RoundPuzzleReveal values) of its secret puzzles, sorted - [] = no secret puzzle
        public array $secretRevealModes = [],
        // The round's public URL part - null = no round pages (and no analytics studio) yet
        public null|string $slug = null,
        // "Unboxed" (world-series-rules.md §7): no box picture until the round's results are first published
        public bool $unboxed = false,
    ) {
    }
}
