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
        // The round's stopwatch start while it has one (GetEditionRounds) - startedAt() lets an early start win
        public null|DateTimeImmutable $stopwatchStartedAt = null,
    ) {
    }

    /**
     * When the round began for the pages that ask "has it started / is it over": its schedule - or its stopwatch's start
     * when the organisers started it before the schedule (a rehearsal, a demo built on the real dates months ahead -
     * spws-demo-ux-leftovers.md §4). A stopwatch started late changes nothing: the schedule already said "started".
     */
    public function startedAt(DateTimeImmutable $now): DateTimeImmutable
    {
        if ($this->stopwatchStartedAt !== null && $this->stopwatchStartedAt <= $now && $this->stopwatchStartedAt < $this->startsAt) {
            return $this->stopwatchStartedAt;
        }

        return $this->startsAt;
    }

    public function isPick(): bool
    {
        return $this->puzzleFormat === RoundPuzzleFormat::Pick && $this->puzzlesToSolve !== null;
    }

    /**
     * The puzzles of a `pick` round of K ≥ 2 that get their own ranking on the public round page - "challenges" of a
     * solve-all block (real events W4, world-series-rules.md "Review amendments"): only puzzles shown to everybody (a
     * hidden puzzle's ranking would tell how it went before its reveal). Empty for every other round.
     *
     * @return list<RoundPuzzleSlot>
     */
    public function challengeSlots(): array
    {
        if ($this->isPick() === false || $this->puzzlesToSolve < 2) {
            return [];
        }

        return array_values(array_filter($this->puzzleSlots, static fn (RoundPuzzleSlot $slot): bool => $slot->isHidden() === false));
    }

    /**
     * The challenge `?challenge=` names - its place in the round (1..N) or its label (any case) - or null when it names
     * none of challengeSlots().
     */
    public function challengeSlot(null|string $challenge): null|RoundPuzzleSlot
    {
        $challenge = trim((string) $challenge);

        if ($challenge === '') {
            return null;
        }

        $slots = $this->challengeSlots();

        foreach ($slots as $slot) {
            if ((string) $slot->position === $challenge) {
                return $slot;
            }
        }

        foreach ($slots as $slot) {
            if (mb_strtolower($slot->label) === mb_strtolower($challenge)) {
                return $slot;
            }
        }

        return null;
    }
}
