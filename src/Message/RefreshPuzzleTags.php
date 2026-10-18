<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Writes puzzle_tag from the `tags` analyses and the corrections (docs/features/insights/tags.md) - the daily run
 * (myspeedpuzzling:refresh-puzzle-tags) and right after a correction (only that puzzle). Answers a
 * PuzzleTagWriteOutcome.
 */
readonly final class RefreshPuzzleTags
{
    /**
     * @param null|list<string> $puzzleIds only these puzzles, rewritten whatever their watermark says; null = every
     *                                     puzzle whose input changed since its tags were last written
     * @param bool $full every puzzle with an analysis, corrections or tags (after a change of the writer's rules)
     */
    public function __construct(
        public null|array $puzzleIds = null,
        public bool $full = false,
    ) {
    }
}
