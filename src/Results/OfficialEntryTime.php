<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * What "Add to my profile" of a published official result fills into the add-time form (OfficialEntryTimePrefill,
 * docs/features/competitions-management/official-results.md). Only a pre-fill: the player checks and saves it like
 * any other time - nothing about the official entry is stored with it.
 *
 * A pair/team result never opens as a solo time: the form starts in the round's mode (Pair / Team, no Solo) with every
 * person the organiser recorded besides the viewer, and says so when the people are fewer than the round needs.
 */
readonly final class OfficialEntryTime
{
    /**
     * @param list<string> $groupPlayers co-puzzlers as the form posts them: "#CODE" of a linked member, else a guest name
     * @param list<string> $memberChoices nobody of the pair/team is linked and the viewer's name is not clearly one of
     *                                    them: the names to pick "this one is me" from (by position, `official_member`)
     */
    public function __construct(
        public string $puzzleId,
        public int $seconds,
        // The round's start date in the round's time zone
        public DateTimeImmutable $finishedAt,
        public array $groupPlayers,
        // The pair's/team's name - only when the form may still name it (no such pair/team yet, or an unnamed one)
        public null|string $teamName,
        public string $roundName,
        public string $competitionName,
        public RoundCategory $category = RoundCategory::Solo,
        public array $memberChoices = [],
    ) {
    }

    public function isGroup(): bool
    {
        return $this->category !== RoundCategory::Solo;
    }

    /**
     * The co-puzzler picker's mode: the round's - a "pair" the organiser recorded with more people is a team.
     */
    public function pickerMode(): null|string
    {
        if ($this->isGroup() === false) {
            return null;
        }

        return $this->category === RoundCategory::Duo && count($this->groupPlayers) <= 1 ? 'pair' : 'team';
    }

    /**
     * How many more people the form needs for the round's category (a pair is 2, a team at least 3, the viewer
     * included) - the organiser did not record them, or the viewer has not said which one they are.
     */
    public function missingPeople(): int
    {
        $needed = match ($this->category) {
            RoundCategory::Solo => 1,
            RoundCategory::Duo => 2,
            RoundCategory::Team => 3,
        };

        return max(0, $needed - 1 - count($this->groupPlayers));
    }

    /**
     * The picker's mode for a form that holds this many co-puzzlers: the round's - except more people than a pair
     * holds, shown as they are (a team) next to the form error asking to keep one.
     */
    public function pickerModeWith(int $coPuzzlers): null|string
    {
        $mode = $this->pickerMode();

        return $mode === 'pair' && $coPuzzlers > 1 ? null : $mode;
    }

    /**
     * Why a save with this many co-puzzlers (the tracker not counted) is not this pair's/team's result - null when it
     * is: a pair is exactly two people, a team three or more. A solo entry takes any group.
     *
     * @return null|array{message: string, count: int}
     */
    public function groupRefusal(int $coPuzzlers): null|array
    {
        return match ($this->pickerMode()) {
            'pair' => match (true) {
                $coPuzzlers < 1 => ['message' => 'puzzle_add.official_entry.pair_needs_partner', 'count' => 1],
                $coPuzzlers > 1 => ['message' => 'puzzle_add.official_entry.pair_too_many', 'count' => $coPuzzlers + 1],
                default => null,
            },
            'team' => $coPuzzlers < 2 ? ['message' => 'puzzle_add.official_entry.team_needs_people', 'count' => 2 - $coPuzzlers] : null,
            default => null,
        };
    }

    public function hours(): int
    {
        return intdiv($this->seconds, 3600);
    }

    public function minutes(): int
    {
        return intdiv($this->seconds % 3600, 60);
    }

    public function secondsPart(): int
    {
        return $this->seconds % 60;
    }
}
