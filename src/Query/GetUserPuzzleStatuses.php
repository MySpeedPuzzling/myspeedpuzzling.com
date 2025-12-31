<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;
use Symfony\Contracts\Service\ResetInterface;

final class GetUserPuzzleStatuses implements ResetInterface
{
    /** @var array<string, UserPuzzleStatuses> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $database,
    ) {
    }

    public function reset(): void
    {
        $this->cache = [];
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
        // Includes holder/owner names for lent/borrowed and listing type for sell/swap
        $query = <<<SQL
SELECT puzzle_id, NULL::text as lent_puzzle_id, NULL::text as collection_id, NULL::text as collection_name, NULL::text as sell_swap_item_id, NULL::text as holder_name, NULL::text as owner_name, NULL::text as listing_type, 'solved' as status FROM puzzle_solving_time WHERE player_id = :playerId OR (team IS NOT NULL AND EXISTS (SELECT 1 FROM json_array_elements(team -> 'puzzlers') AS puzzler WHERE puzzler ->> 'player_id' = :playerId))
UNION ALL
SELECT puzzle_id, NULL::text as lent_puzzle_id, NULL::text as collection_id, NULL::text as collection_name, NULL::text as sell_swap_item_id, NULL::text as holder_name, NULL::text as owner_name, NULL::text as listing_type, 'wishlist' as status FROM wish_list_item WHERE player_id = :playerId
UNION ALL
SELECT ci.puzzle_id, NULL::text as lent_puzzle_id, COALESCE(c.id::text, '__system_collection__') as collection_id, c.name as collection_name, NULL::text as sell_swap_item_id, NULL::text as holder_name, NULL::text as owner_name, NULL::text as listing_type, 'collection' as status
FROM collection_item ci
LEFT JOIN collection c ON c.id = ci.collection_id
WHERE ci.player_id = :playerId
UNION ALL
SELECT lp.puzzle_id, lp.id::text as lent_puzzle_id, NULL::text as collection_id, NULL::text as collection_name, NULL::text as sell_swap_item_id, NULL::text as holder_name, COALESCE(owner.name, lp.owner_name, '') as owner_name, NULL::text as listing_type, 'borrowed' as status
FROM lent_puzzle lp
LEFT JOIN player owner ON lp.owner_player_id = owner.id
WHERE lp.current_holder_player_id = :playerId
AND (lp.owner_player_id IS NULL OR lp.owner_player_id != :playerId)
UNION ALL
SELECT lp.puzzle_id, lp.id::text as lent_puzzle_id, NULL::text as collection_id, NULL::text as collection_name, NULL::text as sell_swap_item_id, COALESCE(holder.name, lp.current_holder_name, '') as holder_name, NULL::text as owner_name, NULL::text as listing_type, 'lent' as status
FROM lent_puzzle lp
LEFT JOIN player holder ON lp.current_holder_player_id = holder.id
WHERE lp.owner_player_id = :playerId
UNION ALL
SELECT puzzle_id, NULL::text as lent_puzzle_id, NULL::text as collection_id, NULL::text as collection_name, id::text as sell_swap_item_id, NULL::text as holder_name, NULL::text as owner_name, listing_type, 'sell_swap' as status FROM sell_swap_list_item WHERE player_id = :playerId
SQL;

        /** @var array<array{puzzle_id: string, lent_puzzle_id: string|null, collection_id: string|null, collection_name: string|null, sell_swap_item_id: string|null, holder_name: string|null, owner_name: string|null, listing_type: string|null, status: string}> $rows */
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
        $sellSwapItemIds = [];
        /** @var array<string, array<string, string>> $puzzleCollections */
        $puzzleCollections = [];
        /** @var array<string, string> $lentToNames */
        $lentToNames = [];
        /** @var array<string, string> $borrowedFromNames */
        $borrowedFromNames = [];
        /** @var array<string, string> $sellSwapTypes */
        $sellSwapTypes = [];

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
                    // Build puzzleCollections mapping: puzzleId => [collectionId => collectionName]
                    if ($row['collection_id'] !== null) {
                        if (!isset($puzzleCollections[$puzzleId])) {
                            $puzzleCollections[$puzzleId] = [];
                        }
                        // Use collection name or '__system_collection__' marker for system collection
                        $puzzleCollections[$puzzleId][$row['collection_id']] = $row['collection_name'] ?? '__system_collection__';
                    }
                    break;
                case 'borrowed':
                    $borrowed[$puzzleId] = true;
                    if ($row['lent_puzzle_id'] !== null) {
                        $borrowedPuzzleIds[$puzzleId] = $row['lent_puzzle_id'];
                    }
                    if ($row['owner_name'] !== null && $row['owner_name'] !== '') {
                        $borrowedFromNames[$puzzleId] = $row['owner_name'];
                    }
                    break;
                case 'lent':
                    $lent[$puzzleId] = true;
                    if ($row['lent_puzzle_id'] !== null) {
                        $lentPuzzleIds[$puzzleId] = $row['lent_puzzle_id'];
                    }
                    if ($row['holder_name'] !== null && $row['holder_name'] !== '') {
                        $lentToNames[$puzzleId] = $row['holder_name'];
                    }
                    break;
                case 'sell_swap':
                    $sellSwap[$puzzleId] = true;
                    if ($row['sell_swap_item_id'] !== null) {
                        $sellSwapItemIds[$puzzleId] = $row['sell_swap_item_id'];
                    }
                    if ($row['listing_type'] !== null) {
                        $sellSwapTypes[$puzzleId] = $row['listing_type'];
                    }
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
            puzzleCollections: $puzzleCollections,
            sellSwapItemIds: $sellSwapItemIds,
            lentToNames: $lentToNames,
            borrowedFromNames: $borrowedFromNames,
            sellSwapTypes: $sellSwapTypes,
        );

        $this->cache[$playerId] = $result;

        return $result;
    }
}
