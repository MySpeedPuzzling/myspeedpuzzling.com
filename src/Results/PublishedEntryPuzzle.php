<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\OfficialEntryProfileState;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;

/**
 * One puzzle a `pick` round's entry chose, as a public page may show it (competition toolkit A5, A-round-formats.md
 * §2.7-2.8): its neutral label always, the puzzle itself (name, picture, pieces) only once the public reveal rule
 * shows it to everybody (RoundPuzzlesVisibleNow's slot) - never a hidden puzzle's name, picture, piece count or id.
 */
readonly final class PublishedEntryPuzzle
{
    public function __construct(
        // competition_round_puzzle.id - what the record and "Add to my profile" (`round_puzzle`) refer to
        public string $roundPuzzleId,
        // 1..N in the organiser's order, and the organiser's label or the letter of the place
        public int $position,
        public string $label,
        // Null while the puzzle is hidden from everybody - the page then names it by its label only
        public null|VisibleRoundPuzzle $puzzle,
        public bool $done,
        // Done only: the elapsed round time it was finished at (a cumulative split)
        public null|int $atSeconds,
        // Done only: its own time - its split minus the previous puzzle's (null when a split it needs is missing)
        public null|int $duration,
        // Not done only: the pieces placed on it when the time ran out
        public null|int $piecesPlaced,
        // "Add to my profile" of this puzzle for the viewer (GetPublishedRoundResults) - null = nothing offered
        public null|OfficialEntryProfileState $profileState = null,
    ) {
    }

    /**
     * The record's puzzles in solving order, each with what the public may see of it. An item naming no puzzle of the
     * round (the write path's guards make that impossible) is left out.
     *
     * @param list<RoundPuzzleSlot> $slots the round's puzzles now (RoundPuzzlesVisibleNow)
     * @return list<self>
     */
    public static function listOf(null|RoundEntryPuzzles $record, array $slots): array
    {
        if ($record === null) {
            return [];
        }

        $slotsById = [];
        foreach ($slots as $slot) {
            $slotsById[strtolower($slot->roundPuzzleId)] = $slot;
        }

        $chips = [];

        foreach ($record->items as $index => $item) {
            $slot = $slotsById[$item->roundPuzzleId] ?? null;

            if ($slot === null) {
                continue;
            }

            $chips[] = new self(
                roundPuzzleId: $item->roundPuzzleId,
                position: $slot->position,
                label: $slot->label,
                puzzle: $slot->puzzle,
                done: $item->done,
                atSeconds: $item->atSeconds,
                duration: $item->done ? $record->duration($index) : null,
                piecesPlaced: $item->piecesPlaced,
            );
        }

        return $chips;
    }

    public function withProfileState(null|OfficialEntryProfileState $profileState): self
    {
        return new self(
            roundPuzzleId: $this->roundPuzzleId,
            position: $this->position,
            label: $this->label,
            puzzle: $this->puzzle,
            done: $this->done,
            atSeconds: $this->atSeconds,
            duration: $this->duration,
            piecesPlaced: $this->piecesPlaced,
            profileState: $profileState,
        );
    }

    /**
     * The puzzle a time can be logged on from here: shown to everybody with its picture (the add form's own secret
     * puzzle rules would refuse it otherwise) - the same condition as a one-puzzle round's "Add to my profile".
     */
    public function profilePuzzleId(): null|string
    {
        return $this->puzzle !== null && $this->puzzle->imageHidden === false ? $this->puzzle->puzzleId : null;
    }
}
