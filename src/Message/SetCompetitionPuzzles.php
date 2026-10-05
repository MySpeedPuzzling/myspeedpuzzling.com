<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The competition's own puzzles ("Competition puzzles" on the event page) - the puzzles of its tag, replaced by this
 * list. A competition without a tag gets one.
 */
readonly final class SetCompetitionPuzzles
{
    /**
     * @param list<string> $puzzleIds
     */
    public function __construct(
        public string $competitionId,
        public array $puzzleIds,
    ) {
    }
}
