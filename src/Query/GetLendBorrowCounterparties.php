<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\LendBorrowCounterparty;

/**
 * The people a player has lent to / borrowed from, most frequent and most
 * recent first - the "smart" suggestions of the multiscan person picker.
 * Bilateral lending history (deliberately not blocklist-filtered, like the
 * other lend/borrow queries).
 */
readonly final class GetLendBorrowCounterparties
{
    private const int LIMIT = 12;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<LendBorrowCounterparty>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
WITH mine AS (
    SELECT
        CASE WHEN lpt.from_player_id = :playerId THEN 'lend' ELSE 'borrow' END AS role,
        CASE WHEN lpt.from_player_id = :playerId THEN lpt.to_player_id ELSE lpt.from_player_id END AS other_id,
        CASE WHEN lpt.from_player_id = :playerId THEN lpt.to_player_name ELSE lpt.from_player_name END AS other_name,
        lpt.transferred_at
    FROM lent_puzzle_transfer lpt
    WHERE lpt.from_player_id = :playerId OR lpt.to_player_id = :playerId
)
SELECT
    mine.role,
    p.code AS player_code,
    p.name AS player_name,
    p.avatar AS player_avatar,
    NULLIF(TRIM(mine.other_name), '') AS other_name,
    COUNT(*) AS times,
    MAX(mine.transferred_at) AS last_at
FROM mine
LEFT JOIN player p ON p.id = mine.other_id
WHERE mine.other_id IS NOT NULL OR NULLIF(TRIM(mine.other_name), '') IS NOT NULL
GROUP BY mine.role, p.code, p.name, p.avatar, NULLIF(TRIM(mine.other_name), '')
ORDER BY times DESC, last_at DESC
LIMIT :limit
SQL;

        /** @var list<array{role: string, player_code: null|string, player_name: null|string, player_avatar: null|string, other_name: null|string, times: int|string, last_at: string}> $rows */
        $rows = $this->database
            ->executeQuery($query, ['playerId' => $playerId, 'limit' => self::LIMIT])
            ->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            if ($row['player_code'] !== null) {
                $value = '#' . $row['player_code'];
                $label = $row['player_name'] ?? $row['player_code'];
            } elseif ($row['other_name'] !== null) {
                $value = $row['other_name'];
                $label = $row['other_name'];
            } else {
                continue;
            }

            $result[] = new LendBorrowCounterparty(
                role: $row['role'] === 'lend' ? 'lend' : 'borrow',
                value: $value,
                label: $label,
                code: $row['player_code'],
                avatar: $row['player_avatar'],
                times: (int) $row['times'],
            );
        }

        return $result;
    }
}
