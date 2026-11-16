<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The round with a time limit an unfinished result would be recorded against (UnfinishedResultHint) - what the add/edit
 * form needs to decide whether "I didn't finish within the time limit" is a question worth asking
 * (docs/features/competitions-management/unfinished-results-plan.md, "Refined 2026-11-16" rules 1-2). The round's name
 * and limit are public on the event page; nothing about the puzzle is in it.
 */
readonly final class UnfinishedRoundHint
{
    public function __construct(
        public string $name,
        public int $minutesLimit,
        // solo / duo / team (RoundCategory)
        public string $category,
    ) {
    }

    public function limitSeconds(): int
    {
        return $this->minutesLimit * 60;
    }

    /**
     * The question is asked while the time is empty or over the limit - a time within it obviously finished
     */
    public function asksFor(int $typedSeconds): bool
    {
        return $typedSeconds <= 0 || $typedSeconds > $this->limitSeconds();
    }

    /**
     * @return array{name: string, minutesLimit: int, category: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'minutesLimit' => $this->minutesLimit,
            'category' => $this->category,
        ];
    }
}
