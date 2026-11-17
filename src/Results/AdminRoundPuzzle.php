<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundPuzzleLabel;

readonly final class AdminRoundPuzzle
{
    public function __construct(
        public string $roundPuzzleId,
        public bool $hideUntilRoundStarts,
        public null|string $hideMode,
        public AdminPuzzle $puzzle,
        // The one reveal moment (RoundPuzzleReveal): automatic / scheduled / manual; when (UTC, null = manual, not
        // revealed yet, or not secret); whether this round keeps the puzzle secret on the whole site too
        public string $revealMode = 'automatic',
        public null|string $revealsAt = null,
        public bool $hidesEverywhere = false,
        // Competition toolkit A2: its place in the organiser's order (1..N) and the organiser's label (null = the letter
        // of its place - effectiveLabel in the JSON)
        public int $position = 0,
        public null|string $label = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->puzzle->toArray(),
            'roundPuzzleId' => $this->roundPuzzleId,
            'hideUntilRoundStarts' => $this->hideUntilRoundStarts,
            'hideMode' => $this->hideMode,
            'revealMode' => $this->revealMode,
            'revealsAt' => $this->revealsAt,
            'hidesEverywhere' => $this->hidesEverywhere,
            'position' => $this->position,
            'label' => $this->label,
            'effectiveLabel' => RoundPuzzleLabel::effective($this->label, $this->position),
        ];
    }
}
