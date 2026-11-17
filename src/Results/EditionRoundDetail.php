<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class EditionRoundDetail
{
    /**
     * @param array<EditionRoundPuzzle> $puzzles
     * @param list<RoundPuzzleSlot> $puzzleSlots a `pick` round's puzzles as places with their labels (RoundPuzzlesVisibleNow,
     *                                          hidden ones by their label only) - empty for every other round
     */
    public function __construct(
        public string $id,
        public string $name,
        public DateTimeImmutable $startsAt,
        // null = no limit given (CompetitionRound::$minutesLimit)
        public null|int $minutesLimit,
        public RoundCategory $category,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        public array $puzzles,
        // What the round is shown in - see RoundBadgeColor (organiser's colour or palette, contrast-safe text)
        public string $color,
        public string $textColor,
        public null|string $slug = null,
        public null|string $resultsLink = null,
        // The zone startsAt is shown in - see RoundTimezone
        public string $timezone = RoundTimezone::FALLBACK,
        // Nobody said where the event is - $timezone is only the fallback, named without a place (timezone_name())
        public bool $timezoneAssumed = false,
        // The organiser published the round's official results (docs/features/competitions-management/official-results.md)
        public bool $resultsPublished = false,
        // The round's online timing has live video on - the studio has a watch page (docs/features/online-competitions/)
        public bool $onlineVideo = false,
        // false = only the day is known, $startsAt is noon of it - pages show no time (CompetitionRound::$startTimeKnown)
        public bool $startTimeKnown = true,
        // How the round's puzzles are solved, and K of a `pick` round (competition toolkit A2)
        public RoundPuzzleFormat $puzzleFormat = RoundPuzzleFormat::Total,
        public null|int $puzzlesToSolve = null,
        public array $puzzleSlots = [],
    ) {
    }

    public function isPick(): bool
    {
        return $this->puzzleFormat === RoundPuzzleFormat::Pick && $this->puzzlesToSolve !== null;
    }
}
