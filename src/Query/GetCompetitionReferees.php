<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionRefereeListItem;

/**
 * The referees of one competition for its organisers' referees page (live-results.md "Referees"), by name.
 */
readonly final class GetCompetitionReferees
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<CompetitionRefereeListItem>
     */
    public function ofCompetition(string $competitionId): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.code AS player_code,
    player.name AS player_name,
    player.country AS player_country,
    referee.added_at,
    COALESCE(added_by.name, '#' || UPPER(added_by.code)) AS added_by_name
FROM competition_referee referee
INNER JOIN player ON player.id = referee.player_id
LEFT JOIN player added_by ON added_by.id = referee.added_by_id
WHERE referee.competition_id = :competitionId
ORDER BY LOWER(COALESCE(player.name, player.code)), player.code
SQL;

        $rows = $this->database->fetchAllAssociative($query, ['competitionId' => $competitionId]);

        return array_map(static function (array $row): CompetitionRefereeListItem {
            /** @var array{player_id: string, player_code: string, player_name: null|string, player_country: null|string, added_at: string, added_by_name: null|string} $row */
            return CompetitionRefereeListItem::fromDatabaseRow($row);
        }, $rows);
    }
}
