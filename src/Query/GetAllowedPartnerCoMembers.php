<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Which of the players named as co-members of a partner finder listing the lister may name
 * (docs/features/partner-finder.md D1): they exist, there is no block between them in either direction, and the lister
 * may see them - a public player, or a private one who has the lister on their allow list. Write side: the handler gets
 * the lister's id, never a security token.
 */
readonly final class GetAllowedPartnerCoMembers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $playerIds
     *
     * @return list<string> the allowed ids among $playerIds, lower case
     */
    public function of(string $listerId, array $playerIds): array
    {
        $playerIds = array_values(array_filter($playerIds, static fn (string $id): bool => Uuid::isValid($id)));

        if ($playerIds === [] || Uuid::isValid($listerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT co.id
FROM player co
WHERE co.id IN (:ids)
    AND (
        co.is_private = false
        OR EXISTS (
            SELECT 1 FROM private_profile_viewer ppv
            WHERE ppv.owner_id = co.id AND ppv.viewer_id = :listerId
        )
    )
    AND NOT EXISTS (
        SELECT 1 FROM user_block ub
        WHERE (ub.blocker_id = co.id AND ub.blocked_id = :listerId)
            OR (ub.blocker_id = :listerId AND ub.blocked_id = co.id)
    )
SQL;

        /** @var list<string> $ids */
        $ids = $this->database
            ->executeQuery($query, [
                'ids' => array_map(strtolower(...), $playerIds),
                'listerId' => $listerId,
            ], [
                'ids' => ArrayParameterType::STRING,
            ])
            ->fetchFirstColumn();

        return array_map(strtolower(...), $ids);
    }
}
