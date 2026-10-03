<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ComparisonLineUpItem;

/**
 * @phpstan-import-type ComparisonLineUpItemRow from ComparisonLineUpItem
 */
readonly final class GetComparisonLineUp
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Every line-up of the player (Solo, Pairs, Teams), oldest first - refs only. Who the subjects are, and whether the
     * viewer may still see them, the comparison resolves with the viewer's own visibility on every read.
     *
     * @return list<ComparisonLineUpItem>
     */
    public function forPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT
    comparison_subject.id,
    comparison_subject.subject_player_id,
    comparison_subject.subject_team_id,
    team.size AS team_size,
    comparison_subject.added_at,
    COALESCE(comparison_subject.subject_player_id = comparison_subject.player_id, false) AS is_self
FROM comparison_subject
LEFT JOIN puzzling_team team ON team.id = comparison_subject.subject_team_id
WHERE comparison_subject.player_id = :playerId
ORDER BY comparison_subject.added_at, comparison_subject.id
SQL;

        /** @var list<ComparisonLineUpItemRow> $rows */
        $rows = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(ComparisonLineUpItem::fromDatabaseRow(...), $rows);
    }
}
