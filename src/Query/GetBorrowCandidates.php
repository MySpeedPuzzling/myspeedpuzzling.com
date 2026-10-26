<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\BorrowCandidate;

/**
 * Who the asker may ask to borrow these puzzles from (docs/features/wishlist-availability.md) - the one rule, for the
 * wishlist page, the ask sheet and the AskToBorrowPuzzles handler alike. Takes the asker's id, never a security token.
 *
 * - candidate_owners: the asker's favorites. The one place to widen when anybody with a public collection may be asked.
 * - the puzzle is in a collection of theirs that anybody may see: the system collection while their "Puzzle collection"
 *   list is public, a custom collection while that collection is public - nothing that is not on their pages already
 * - a private owner only when they have the asker on their allow list; never with a block between the two, either way
 * - never a secret puzzle, only a puzzle on the asker's wishlist
 */
readonly final class GetBorrowCandidates
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $puzzleIds
     *
     * @return list<BorrowCandidate> per puzzle, owners by name
     */
    public function forPuzzles(string $askerId, array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_filter(array_map(strtolower(...), $puzzleIds), static fn (string $id): bool => Uuid::isValid($id))));

        if ($puzzleIds === [] || Uuid::isValid($askerId) === false) {
            return [];
        }

        $query = <<<SQL
WITH candidate_owners AS (
    SELECT DISTINCT favorite_id::uuid AS owner_id
    FROM player asker
    CROSS JOIN LATERAL json_array_elements_text(asker.favorite_players::json) AS favorite_id
    WHERE asker.id = :askerId
)
SELECT * FROM (
SELECT DISTINCT
    ci.puzzle_id,
    owner.id AS owner_id,
    owner.name AS owner_name,
    owner.code AS owner_code,
    owner.avatar AS owner_avatar,
    owner.country AS owner_country,
    EXISTS (
        SELECT 1 FROM lent_puzzle lp
        WHERE lp.owner_player_id = owner.id AND lp.puzzle_id = ci.puzzle_id
    ) AS lent_out
FROM candidate_owners
JOIN player owner ON owner.id = candidate_owners.owner_id
JOIN collection_item ci ON ci.player_id = owner.id AND ci.puzzle_id = ANY(:puzzleIds)
LEFT JOIN collection c ON c.id = ci.collection_id
JOIN puzzle p ON p.id = ci.puzzle_id
WHERE owner.id <> :askerId
    AND (
        (ci.collection_id IS NULL AND owner.puzzle_collection_visibility = 'public')
        OR c.visibility = 'public'
    )
    AND (p.hide_until IS NULL OR p.hide_until <= :now::timestamp)
    AND EXISTS (SELECT 1 FROM wish_list_item wli WHERE wli.player_id = :askerId AND wli.puzzle_id = ci.puzzle_id)
    AND (
        owner.is_private = false
        OR EXISTS (
            SELECT 1 FROM private_profile_viewer ppv
            WHERE ppv.owner_id = owner.id AND ppv.viewer_id = :askerId
        )
    )
    AND NOT EXISTS (
        SELECT 1 FROM user_block ub
        WHERE (ub.blocker_id = owner.id AND ub.blocked_id = :askerId)
            OR (ub.blocker_id = :askerId AND ub.blocked_id = owner.id)
    )
) candidates
ORDER BY candidates.puzzle_id, LOWER(candidates.owner_name) NULLS LAST, candidates.owner_code
SQL;

        /** @var list<array{puzzle_id: string, owner_id: string, owner_name: null|string, owner_code: string, owner_avatar: null|string, owner_country: null|string, lent_out: bool}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'askerId' => strtolower($askerId),
                'puzzleIds' => '{' . implode(',', $puzzleIds) . '}',
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static fn (array $row): BorrowCandidate => new BorrowCandidate(
            puzzleId: $row['puzzle_id'],
            ownerId: $row['owner_id'],
            ownerName: $row['owner_name'],
            ownerCode: $row['owner_code'],
            ownerAvatar: $row['owner_avatar'],
            ownerCountry: $row['owner_country'],
            lentOut: $row['lent_out'],
        ), $rows);
    }
}
