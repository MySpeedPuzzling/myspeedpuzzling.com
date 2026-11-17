<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioVisibility;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * What every analytics studio answer starts from (AnalyticsStudioContextLoader): the round and what of its puzzles
 * everybody may see right now (RoundPuzzlesVisibleNow) - the only puzzles any studio statement reads about.
 */
readonly final class AnalyticsStudioContext
{
    /** @var array<string, VisibleRoundPuzzle> slot key (round puzzle id) => visible puzzle, in slot order */
    public array $visible;

    public string $visibleHash;

    public function __construct(
        public AnalyticsStudioRound $round,
        public RoundPuzzlesNow $puzzles,
        public DateTimeImmutable $now,
    ) {
        $this->visible = AnalyticsStudioVisibility::visibleBySlot($puzzles);
        $this->visibleHash = AnalyticsStudioVisibility::hash($puzzles);
    }

    /**
     * @return list<string>
     */
    public function visiblePuzzleIds(): array
    {
        return AnalyticsStudioVisibility::visiblePuzzleIds($this->puzzles);
    }

    /**
     * @return list<int>
     */
    public function visiblePiecesCounts(): array
    {
        return array_values(array_unique(array_map(static fn (VisibleRoundPuzzle $puzzle): int => $puzzle->piecesCount, $this->visible)));
    }

    public function cutOffMs(): int
    {
        return RoundClock::milliseconds($this->round->historyCutOff());
    }
}
