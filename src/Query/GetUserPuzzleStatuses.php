<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;

final class GetUserPuzzleStatuses
{
    /** @var array<string, UserPuzzleStatuses> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $database,
    ) {
    }

    public function byPlayerId(null|string $playerId): UserPuzzleStatuses
    {
        if ($playerId === null) {
            return UserPuzzleStatuses::empty();
        }

        if (isset($this->cache[$playerId])) {
            return $this->cache[$playerId];
        }

        // Fetch all statuses in one query using UNION ALL
        $query = <<<SQL
SELECT puzzle_id, NULL::text as lent_puzzle_id, 'solved' as status FROM puzzle_solving_time WHERE player_id = :playerId
UNION ALL
SELECT puzzle_id, NULL::text as lent_puzzle_id, 'wishlist' as status FROM wish_list_item WHERE player_id = :playerId
UNION ALL
SELECT puzzle_id, NULL::text as lent_puzzle_id, 'collection' as status FROM collection_item WHERE player_id = :playerId
UNION ALL
SELECT puzzle_id, id::text as lent_puzzle_id, 'borrowed' as status FROM lent_puzzle
    WHERE current_holder_player_id = :playerId
    AND (owner_player_id IS NULL OR owner_player_id != :playerId)
UNION ALL
SELECT puzzle_id, id::text as lent_puzzle_id, 'lent' as status FROM lent_puzzle WHERE owner_player_id = :playerId
UNION ALL
SELECT puzzle_id, NULL::text as lent_puzzle_id, 'sell_swap' as status FROM sell_swap_list_item WHERE player_id = :playerId
SQL;

        /** @var array<array{puzzle_id: string, lent_puzzle_id: string|null, status: string}> $rows */
        $rows = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        // Process results into separate arrays
        $solved = [];
        $wishlist = [];
        $collection = [];
        $borrowed = [];
        $lent = [];
        $sellSwap = [];
        $lentPuzzleIds = [];
        $borrowedPuzzleIds = [];

        foreach ($rows as $row) {
            $puzzleId = $row['puzzle_id'];
            switch ($row['status']) {
                case 'solved':
                    $solved[$puzzleId] = true;
                    break;
                case 'wishlist':
                    $wishlist[$puzzleId] = true;
                    break;
                case 'collection':
                    $collection[$puzzleId] = true;
                    break;
                case 'borrowed':
                    $borrowed[$puzzleId] = true;
                    if ($row['lent_puzzle_id'] !== null) {
                        $borrowedPuzzleIds[$puzzleId] = $row['lent_puzzle_id'];
                    }
                    break;
                case 'lent':
                    $lent[$puzzleId] = true;
                    if ($row['lent_puzzle_id'] !== null) {
                        $lentPuzzleIds[$puzzleId] = $row['lent_puzzle_id'];
                    }
                    break;
                case 'sell_swap':
                    $sellSwap[$puzzleId] = true;
                    break;
            }
        }

        // Compute unsolved: puzzles in collection or borrowed that are NOT solved
        $unsolved = [];
        foreach (array_keys($collection) as $puzzleId) {
            if (!isset($solved[$puzzleId])) {
                $unsolved[$puzzleId] = true;
            }
        }
        foreach (array_keys($borrowed) as $puzzleId) {
            if (!isset($solved[$puzzleId])) {
                $unsolved[$puzzleId] = true;
            }
        }

        $result = new UserPuzzleStatuses(
            solved: array_keys($solved),
            wishlist: array_keys($wishlist),
            unsolved: array_keys($unsolved),
            collection: array_keys($collection),
            borrowed: array_keys($borrowed),
            lent: array_keys($lent),
            sellSwap: array_keys($sellSwap),
            lentPuzzleIds: $lentPuzzleIds,
            borrowedPuzzleIds: $borrowedPuzzleIds,
        );

        $this->cache[$playerId] = $result;

        return $result;
    }
}
