<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventDetail;

use SpeedPuzzling\Web\Query\GetCompetitionPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\PuzzleOverview;

/**
 * The puzzle cards of an edition or event page outside its rounds (docs/features/events-page/detail-pages.md, "More
 * puzzles of this event") - one rule for both pages: the event's tagged puzzles, else - only without rounds - the
 * puzzles people logged times for there (most logged first, at most SOLVED_PUZZLES_LIMIT); never a puzzle that is in a
 * round (the timeline shows it there).
 */
readonly final class EventPagePuzzles
{
    // A championship has at most ~20; a perpetual online event collects hundreds - the most logged ones are enough
    public const int SOLVED_PUZZLES_LIMIT = 24;

    public function __construct(
        private GetPuzzleOverview $getPuzzleOverview,
        private GetCompetitionPuzzles $getCompetitionPuzzles,
    ) {
    }

    /**
     * @param list<EditionRoundDetail> $rounds
     *
     * @return list<PuzzleOverview>
     */
    public function resolve(CompetitionEvent $event, array $rounds): array
    {
        $puzzles = $event->tagId !== null ? array_values($this->getPuzzleOverview->byTagId($event->tagId)) : [];

        if ($puzzles === [] && $rounds === []) {
            $puzzles = $this->getCompetitionPuzzles->solvedPuzzleOverviews($event->id, self::SOLVED_PUZZLES_LIMIT);
        }

        $inRounds = array_flip(self::roundPuzzleIds($rounds));

        return array_values(array_filter($puzzles, static fn (PuzzleOverview $puzzle): bool => isset($inRounds[$puzzle->puzzleId]) === false));
    }

    /**
     * The puzzles whose difficulty the page shows: the rounds' and the cards'
     *
     * @param list<EditionRoundDetail> $rounds
     * @param list<PuzzleOverview> $puzzles
     *
     * @return list<string>
     */
    public static function difficultyIds(array $rounds, array $puzzles): array
    {
        return array_values(array_unique([
            ...self::roundPuzzleIds($rounds),
            ...array_map(static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId, $puzzles),
        ]));
    }

    /**
     * @param list<EditionRoundDetail> $rounds
     *
     * @return list<string>
     */
    private static function roundPuzzleIds(array $rounds): array
    {
        $ids = [];

        foreach ($rounds as $round) {
            foreach ($round->puzzles as $puzzle) {
                $ids[] = $puzzle->puzzleId;
            }
        }

        return array_values(array_unique($ids));
    }
}
