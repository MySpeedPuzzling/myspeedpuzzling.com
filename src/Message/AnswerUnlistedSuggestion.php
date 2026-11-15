<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Not this one": the player refuses the catalogue puzzle MySpeedPuzzling suggested for one of their unlisted puzzles
 * (docs/features/data-import/unlisted-puzzles.md "When the catalogue grows"). The suggestion is never made again.
 * Saying yes is PairUnlistedPuzzle.
 */
readonly final class AnswerUnlistedSuggestion
{
    public function __construct(
        public string $unlistedPuzzleId,
        public string $playerId,
    ) {
    }
}
