<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\OfficialEntryProfileState;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * One row of a round's published official results (GetPublishedRoundResults) - a ranked entry: a person of a solo
 * round or a pair/team. The rank was computed over every ranked entry of the round, before the rows hidden from the
 * viewer were dropped, so it is the official place whatever the viewer may see.
 *
 * A `pick` round's entry carries its per-puzzle record as stored ($puzzles, round puzzle ids only) and, on the public
 * round page, the chips resolved by the public reveal rule ($puzzleChips, PublishedEntryPuzzle::listOf()).
 */
readonly final class PublishedRoundEntry
{
    /**
     * @param list<PublishedRoundEntrant> $entrants the person, or the members of the pair/team (by name)
     * @param list<PublishedEntryPuzzle> $puzzleChips the chosen puzzles in solving order (pick rounds on the round page)
     */
    public function __construct(
        public RoundEntryRef $ref,
        public int $rank,
        public RoundEntryResult $result,
        public bool $qualified,
        public bool $isTeam,
        public null|string $teamName,
        public array $entrants,
        // The viewer is linked to this entry: the person, or a member of the pair/team
        public bool $isViewers,
        public null|OfficialEntryProfileState $profileState = null,
        // A `pick` round's per-puzzle record (competition toolkit A3) - null on every other round and without one
        public null|RoundEntryPuzzles $puzzles = null,
        public array $puzzleChips = [],
    ) {
    }

    public function hasLinkedPlayer(): bool
    {
        foreach ($this->entrants as $entrant) {
            if ($entrant->linkedPlayerId !== null) {
                return true;
            }
        }

        return false;
    }

    public function withProfileState(null|OfficialEntryProfileState $profileState): self
    {
        return new self(
            ref: $this->ref,
            rank: $this->rank,
            result: $this->result,
            qualified: $this->qualified,
            isTeam: $this->isTeam,
            teamName: $this->teamName,
            entrants: $this->entrants,
            isViewers: $this->isViewers,
            profileState: $profileState,
            puzzles: $this->puzzles,
            puzzleChips: $this->puzzleChips,
        );
    }

    /**
     * @param list<PublishedEntryPuzzle> $puzzleChips
     */
    public function withPuzzleChips(array $puzzleChips): self
    {
        return new self(
            ref: $this->ref,
            rank: $this->rank,
            result: $this->result,
            qualified: $this->qualified,
            isTeam: $this->isTeam,
            teamName: $this->teamName,
            entrants: $this->entrants,
            isViewers: $this->isViewers,
            profileState: $this->profileState,
            puzzles: $this->puzzles,
            puzzleChips: $puzzleChips,
        );
    }

    /**
     * Some chosen puzzle of a pick round offers "Add to my profile" or is on the viewer's profile already
     */
    public function hasPuzzleProfileStates(): bool
    {
        foreach ($this->puzzleChips as $chip) {
            if ($chip->profileState !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The time "Add to my profile" logs for one chosen puzzle of a `pick` round of K (A-round-formats.md §2.7) - null when
     * that puzzle offers none. The one rule of the round page and the add form's pre-fill (OfficialEntryTimePrefill):
     *
     * - only a puzzle shown to everybody with its picture (PublishedEntryPuzzle::profilePuzzleId());
     * - K = 1: the chosen puzzle, exactly like a one-puzzle round - a finished result's seconds, or an unfinished one's
     *   pieces placed when the round has a time limit;
     * - K ≥ 2: a finished puzzle with its own time known (its split and the previous one's) - that time. A puzzle not
     *   finished offers nothing (its time spent cannot be told).
     *
     * @return null|array{seconds: null|int, piecesPlaced: null|int}
     */
    public function puzzleProfileTime(PublishedEntryPuzzle $chip, int $toSolve, bool $roundHasTimeLimit): null|array
    {
        if ($chip->profilePuzzleId() === null) {
            return null;
        }

        if ($toSolve === 1) {
            if (count($this->puzzleChips) !== 1) {
                return null;
            }

            if ($this->result->isFinished()) {
                return ['seconds' => $this->result->seconds, 'piecesPlaced' => null];
            }

            return $roundHasTimeLimit && $this->result->isUnfinished()
                ? ['seconds' => null, 'piecesPlaced' => $this->result->piecesPlaced]
                : null;
        }

        return $chip->done && $chip->duration !== null
            ? ['seconds' => $chip->duration, 'piecesPlaced' => null]
            : null;
    }

    /**
     * The pieces of the puzzles this `pick` entry chose - "479 / 1000 pcs" of an unfinished result - when all K are
     * recorded and every one of them is shown to everybody (a hidden puzzle's piece count is not public). Else null.
     */
    public function ownPiecesCount(null|int $toSolve): null|int
    {
        if ($toSolve === null || $this->puzzles === null || count($this->puzzles->items) !== $toSolve || count($this->puzzleChips) !== $toSolve) {
            return null;
        }

        $pieces = 0;
        foreach ($this->puzzleChips as $chip) {
            if ($chip->puzzle === null) {
                return null;
            }

            $pieces += $chip->puzzle->piecesCount;
        }

        return $pieces;
    }

    /**
     * The viewer's own entry of a `pick` round of K ≥ 2 finished a puzzle whose own time is not known - nothing to add
     * to their profile for it; the page says per-puzzle times were not recorded.
     */
    public function missesSplits(null|int $toSolve): bool
    {
        return $this->isViewers
            && $toSolve !== null && $toSolve >= 2
            && array_any($this->puzzleChips, static fn (PublishedEntryPuzzle $chip): bool => $chip->done && $chip->duration === null);
    }
}
