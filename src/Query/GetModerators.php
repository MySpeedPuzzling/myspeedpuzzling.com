<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\Moderator;

readonly final class GetModerators
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Review counts cover only the time since the role was granted, so a former
     * admin or a re-appointed moderator is judged on the current stint.
     *
     * @return list<Moderator>
     */
    public function all(): array
    {
        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    player.avatar AS player_avatar,
    player.moderator_since,
    (
        SELECT COUNT(*) FROM puzzle_change_request
        WHERE reviewed_by_id = player.id AND reviewed_at >= player.moderator_since
    ) AS reviewed_change_requests,
    (
        SELECT COUNT(*) FROM puzzle_merge_request
        WHERE reviewed_by_id = player.id AND reviewed_at >= player.moderator_since
    ) AS reviewed_merge_requests,
    GREATEST(
        (SELECT MAX(reviewed_at) FROM puzzle_change_request WHERE reviewed_by_id = player.id AND reviewed_at >= player.moderator_since),
        (SELECT MAX(reviewed_at) FROM puzzle_merge_request WHERE reviewed_by_id = player.id AND reviewed_at >= player.moderator_since)
    ) AS last_review_at
FROM player
WHERE player.moderator_since IS NOT NULL
ORDER BY player.moderator_since DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query);

        return array_map(static function (array $row): Moderator {
            /** @var array{player_id: string, player_name: null|string, player_code: string, player_country: null|string, player_avatar: null|string, moderator_since: string, reviewed_change_requests: int|string, reviewed_merge_requests: int|string, last_review_at: null|string} $row */
            return Moderator::fromDatabaseRow($row);
        }, $rows);
    }
}
