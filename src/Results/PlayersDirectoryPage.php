<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The cards the directory shows and how many puzzlers match its filters in all
 * (docs/features/players-page/README.md, "Browse all").
 */
readonly final class PlayersDirectoryPage
{
    /**
     * @param list<PlayersDirectoryCard> $cards
     */
    public function __construct(
        public array $cards,
        public int $total,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->total > count($this->cards);
    }
}
