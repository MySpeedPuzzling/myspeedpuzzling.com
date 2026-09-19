<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

readonly final class GetUserBlocks
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The blocks the player made themselves - never the admin-imposed ones, which they must not
     * learn about (see docs/features/player-blocklist.md).
     *
     * @return array<array{blocked_id: string, blocked_name: null|string, blocked_code: string, blocked_avatar: null|string, blocked_country: null|string, blocked_at: string}>
     */
    public function forPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT
    ub.blocked_id,
    p.name AS blocked_name,
    p.code AS blocked_code,
    p.avatar AS blocked_avatar,
    p.country AS blocked_country,
    ub.blocked_at
FROM user_block ub
JOIN player p ON ub.blocked_id = p.id
WHERE ub.blocker_id = :playerId
    AND ub.source = 'self'
ORDER BY ub.blocked_at DESC
SQL;

        /** @var array<array{blocked_id: string, blocked_name: null|string, blocked_code: string, blocked_avatar: null|string, blocked_country: null|string, blocked_at: string}> */
        return $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();
    }

    public function isBlocked(string $blockerId, string $blockedId): bool
    {
        $query = <<<SQL
SELECT COUNT(*) FROM user_block WHERE blocker_id = :blockerId AND blocked_id = :blockedId
SQL;

        $result = $this->database
            ->executeQuery($query, ['blockerId' => $blockerId, 'blockedId' => $blockedId])
            ->fetchOne();

        return is_numeric($result) && (int) $result > 0;
    }

    /**
     * Players who block any of the given players. For write-side handlers, which may run with no
     * viewer at all - what a signed-in viewer is shown is HiddenPlayers' job.
     *
     * @param list<string> $playerIds
     * @return list<string>
     */
    public function blockersOf(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        /** @var list<string> */
        return $this->database
            ->executeQuery(
                'SELECT DISTINCT blocker_id FROM user_block WHERE blocked_id IN (:playerIds)',
                ['playerIds' => $playerIds],
                ['playerIds' => ArrayParameterType::STRING],
            )
            ->fetchFirstColumn();
    }
}
