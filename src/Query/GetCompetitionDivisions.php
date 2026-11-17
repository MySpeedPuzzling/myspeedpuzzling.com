<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionDivisionOverview;

/**
 * An event's divisions for the organiser (the divisions page, `GET|PUT /internal-api/competitions/{id}/divisions` -
 * shared-puzzles-and-divisions.md D1): in the event's order, each with the rounds using it and the number of round
 * entries carrying it. Two statements; an event without divisions answers [] after the first.
 */
readonly final class GetCompetitionDivisions
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<CompetitionDivisionOverview>
     */
    public function ofCompetition(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT
    d.id,
    d.name,
    d.short_name,
    d.position,
    (
        SELECT COUNT(*)
        FROM competition_participant_round cpr
        INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
        WHERE cpr.division_id = d.id
    ) + (
        SELECT COUNT(*) FROM competition_team ct WHERE ct.division_id = d.id
    ) AS entries_count
FROM competition_division d
WHERE d.competition_id = :competitionId
ORDER BY d.position, d.name, d.id
SQL, ['competitionId' => $competitionId]);

        if ($rows === []) {
            return [];
        }

        /** @var list<array{round_id: string, division_id: string}> $subsets */
        $subsets = $this->database->fetchAllAssociative(<<<SQL
SELECT crd.round_id, crd.division_id
FROM competition_round_division crd
INNER JOIN competition_round cr ON cr.id = crd.round_id
WHERE cr.competition_id = :competitionId
ORDER BY cr.starts_at, cr.name, cr.id
SQL, ['competitionId' => $competitionId]);

        $roundIds = [];
        foreach ($subsets as $subset) {
            $roundIds[$subset['division_id']][] = $subset['round_id'];
        }

        return array_map(static function (array $row) use ($roundIds): CompetitionDivisionOverview {
            /** @var array{id: string, name: string, short_name: null|string, position: int, entries_count: int} $row */
            return new CompetitionDivisionOverview(
                id: $row['id'],
                name: $row['name'],
                shortName: $row['short_name'],
                position: $row['position'],
                roundIds: $roundIds[$row['id']] ?? [],
                entriesCount: $row['entries_count'],
            );
        }, $rows);
    }
}
