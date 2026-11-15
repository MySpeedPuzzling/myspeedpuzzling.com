<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The daily look at the grown catalogue (docs/features/data-import/unlisted-puzzles.md "When the catalogue grows",
 * `myspeedpuzzling:pair-unlisted-puzzles`): the exact pairing rules are asked again for unlisted puzzles; a strong,
 * unique match becomes a **suggestion** the player confirms - nothing is paired by itself - and each player is told
 * once per run.
 */
readonly final class SuggestUnlistedPuzzlePairings
{
    public function __construct(
        /** At most so many unlisted puzzles a run */
        public int $limit = 5_000,
    ) {
    }
}
