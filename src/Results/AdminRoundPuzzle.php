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
        ];
    }
}
