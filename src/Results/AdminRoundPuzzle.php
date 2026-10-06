<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

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
        ];
    }
}
