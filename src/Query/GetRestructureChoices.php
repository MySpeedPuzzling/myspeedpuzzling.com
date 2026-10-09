<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\RestructureChoice;

/**
 * The candidates of the restructuring pages' selects (docs/features/organizations/README.md "Restructuring tools"):
 * every series / competition that is not rejected - drafts and pending ones included, an organiser restructures their
 * own work before it is public. The pages narrow them to what the viewer may edit (CompetitionPermissions - admins
 * everything); the handlers decide again.
 */
readonly final class GetRestructureChoices
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * By name
     *
     * @return list<RestructureChoice>
     */
    public function series(): array
    {
        /** @var list<array{id: string, name: string, organization_name: null|string, is_draft: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT cs.id, cs.name, o.name AS organization_name, cs.is_draft
FROM competition_series cs
LEFT JOIN organization o ON o.id = cs.organization_id
WHERE cs.rejected_at IS NULL
ORDER BY cs.name, cs.id
SQL);

        return array_map(
            static fn (array $row): RestructureChoice => new RestructureChoice($row['id'], $row['name'], $row['organization_name'], null, $row['is_draft']),
            $rows,
        );
    }

    /**
     * Newest first (undated ones first - they are being prepared)
     *
     * @return list<RestructureChoice>
     */
    public function competitions(): array
    {
        /** @var list<array{id: string, name: string, series_name: null|string, date_from: null|string, is_draft: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT c.id, c.name, cs.name AS series_name,
    COALESCE(c.date_from, (SELECT MIN(cr.starts_at) FROM competition_round cr WHERE cr.competition_id = c.id)) AS date_from,
    (c.is_draft OR COALESCE(cs.is_draft, false)) AS is_draft
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.rejected_at IS NULL
    AND (c.series_id IS NULL OR cs.rejected_at IS NULL)
ORDER BY 4 DESC NULLS FIRST, c.name, c.id
SQL);

        return array_map(
            static fn (array $row): RestructureChoice => new RestructureChoice(
                $row['id'],
                $row['name'],
                // An edition's series - unless its own name says it already ("Lantern Night 5" of "Lantern Night")
                $row['series_name'] !== null && str_contains(mb_strtolower($row['name']), mb_strtolower($row['series_name'])) === false
                    ? $row['series_name']
                    : null,
                $row['date_from'] !== null ? substr($row['date_from'], 0, 10) : null,
                $row['is_draft'],
            ),
            $rows,
        );
    }
}
