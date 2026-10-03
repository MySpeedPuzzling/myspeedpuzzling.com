<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why somebody is "Suggested for you" on the Players page (docs/features/players-page/README.md). A person gets the
 * first reason that applies, in this order - the priority is what GetSuggestedPlayers sorts by.
 */
enum SuggestionReason: string
{
    // Shares a pair/team with somebody the viewer puzzles with, but never with the viewer
    case CoPuzzler = 'co_puzzler';
    // Took part in a competition the viewer took part in too
    case SameEvent = 'same_event';
    // Best 500-piece solo time within GetSuggestedPlayers::SIMILAR_TIME_PERCENT of the viewer's
    case SimilarTime = 'similar_time';
    // At least GetSuggestedPlayers::MIN_SHARED_PUZZLES of the same puzzles logged
    case PuzzlesInCommon = 'puzzles_in_common';

    public static function fromPriority(int $priority): self
    {
        return match ($priority) {
            1 => self::CoPuzzler,
            2 => self::SameEvent,
            3 => self::SimilarTime,
            default => self::PuzzlesInCommon,
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::CoPuzzler => 1,
            self::SameEvent => 2,
            self::SimilarTime => 3,
            self::PuzzlesInCommon => 4,
        };
    }
}
