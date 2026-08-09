<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Resolves an e-mail address to a player id.
 *
 * `user_account.email` is the canonical, uniquely-indexed address; `player.email` is a copy
 * kept in step with it and carries no unique constraint. Both are matched so a player whose
 * copy drifted is still found, with the canonical match winning.
 */
readonly final class GetPlayerIdByEmail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byEmail(string $email): null|string
    {
        $normalized = mb_strtolower(trim($email));

        if ($normalized === '') {
            return null;
        }

        $query = <<<SQL
SELECT player.id AS player_id
FROM player
LEFT JOIN user_account ON user_account.user_id = player.user_id
WHERE LOWER(TRIM(user_account.email)) = :email
    OR LOWER(TRIM(player.email)) = :email
ORDER BY (LOWER(TRIM(user_account.email)) = :email) DESC, player.registered_at ASC
LIMIT 1
SQL;

        $playerId = $this->database
            ->executeQuery($query, ['email' => $normalized])
            ->fetchOne();

        return is_string($playerId) ? $playerId : null;
    }
}
