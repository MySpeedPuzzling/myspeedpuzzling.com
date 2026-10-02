<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Whether anything points at a puzzle yet - a result, a list, a lending, a stopwatch, an event, a moderation request.
 * The rows derived from those (statistics, difficulty, duplicate signals) do not count.
 *
 * A puzzle nothing uses can still be corrected by whoever just added it (AddPuzzleHandler: the add form sent
 * again after its result was refused, docs/features/duplicate-results.md, Layer 1).
 */
readonly final class IsPuzzleInUse
{
    private const array REFERENCES = [
        'puzzle_solving_time' => 'puzzle_id',
        'collection_item' => 'puzzle_id',
        'wish_list_item' => 'puzzle_id',
        'sell_swap_list_item' => 'puzzle_id',
        'sold_swapped_item' => 'puzzle_id',
        'lent_puzzle' => 'puzzle_id',
        'lent_puzzle_transfer' => 'puzzle_id',
        'stopwatch' => 'puzzle_id',
        'conversation' => 'puzzle_id',
        'competition_round_puzzle' => 'puzzle_id',
        'puzzle_change_request' => 'puzzle_id',
        'puzzle_merge_request' => 'source_puzzle_id',
        'tag_puzzle' => 'puzzle_id',
    ];

    public function __construct(
        private Connection $database,
    ) {
    }

    public function check(string $puzzleId): bool
    {
        $exists = [];

        foreach (self::REFERENCES as $table => $column) {
            $exists[] = "EXISTS (SELECT 1 FROM $table WHERE $column = :puzzleId)";
        }

        return (bool) $this->database->fetchOne(
            'SELECT ' . implode(' OR ', $exists),
            ['puzzleId' => $puzzleId],
        );
    }
}
