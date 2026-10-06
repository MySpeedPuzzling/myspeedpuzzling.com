<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A new round and its puzzles in one transaction (the internal API's create with `puzzleIds`): the handler dispatches
 * AddCompetitionRound and SetCompetitionRoundPuzzles inside its own doctrine_transaction, so a refused puzzle list
 * leaves no round behind.
 */
readonly final class AddCompetitionRoundWithPuzzles
{
    /**
     * @param list<string> $puzzleIds distinct, lower case
     */
    public function __construct(
        public AddCompetitionRound $round,
        public array $puzzleIds,
    ) {
    }
}
