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

    public function byUserId(null|string $userId): UserPuzzleStatuses
    {
        if ($userId === null) {
            return UserPuzzleStatuses::empty();
        }

        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        // First get the player_id from user_id
        $playerIdQuery = <<<SQL
SELECT id FROM player WHERE user_id = :userId
SQL;

        /** @var string|false $playerId */
        $playerId = $this->database
            ->executeQuery($playerIdQuery, ['userId' => $userId])
            ->fetchOne();

        if ($playerId === false) {
            return UserPuzzleStatuses::empty();
        }

        // Fetch all statuses in one query using UNION ALL
        $query = <<<SQL
SELECT puzzle_id, 'solved' as status FROM puzzle_solving_time WHERE player_id = :playerId
UNION ALL
SELECT puzzle_id, 'wishlist' as status FROM wish_list_item WHERE player_id = :playerId
UNION ALL
SELECT puzzle_id, 'collection' as status FROM collection_item WHERE player_id = :playerId
UNION ALL
SELECT puzzle_id, 'borrowed' as status FROM lent_puzzle
    WHERE current_holder_player_id = :playerId
    AND (owner_player_id IS NULL OR owner_player_id != :playerId)
UNION ALL
SELECT puzzle_id, 'lent' as status FROM lent_puzzle WHERE owner_player_id = :playerId
UNION ALL
SELECT puzzle_id, 'sell_swap' as status FROM sell_swap_list_item WHERE player_id = :playerId
SQL;

        /** @var array<array{puzzle_id: string, status: string}> $rows */
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
                    break;
                case 'lent':
                    $lent[$puzzleId] = true;
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
        );

        $this->cache[$userId] = $result;

        return $result;
    }
}
