<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetUserBlocks
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<array{blocked_id: string, blocked_name: null|string, blocked_code: string, blocked_avatar: null|string, blocked_at: string}>
     */
    public function forPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT
    ub.blocked_id,
    p.name AS blocked_name,
    p.code AS blocked_code,
    p.avatar AS blocked_avatar,
    ub.blocked_at
FROM user_block ub
JOIN player p ON ub.blocked_id = p.id
WHERE ub.blocker_id = :playerId
ORDER BY ub.blocked_at DESC
SQL;

        /** @var array<array{blocked_id: string, blocked_name: null|string, blocked_code: string, blocked_avatar: null|string, blocked_at: string}> */
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
}
