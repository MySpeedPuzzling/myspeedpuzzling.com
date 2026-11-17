<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\EventToCopy;

/**
 * The event creation wizard's "Copy one of your events" (W-creation-wizard-research.md §3.1): the most recent events and
 * editions the player may edit (GetCompetitionPermissions - their own, their series', their organization's), newest
 * first by date. Organiser tooling for the player's own rows - their drafts (`is_draft`) are listed and tagged, they
 * may copy a draft too.
 */
readonly final class GetEventsToCopy
{
    public function __construct(
        private Connection $database,
        private GetCompetitionPermissions $getCompetitionPermissions,
    ) {
    }

    /**
     * @return list<EventToCopy>
     */
    public function forPlayer(string $playerId, int $limit = 5): array
    {
        $ids = $this->getCompetitionPermissions->forPlayer($playerId)->editableCompetitionIds();

        if ($ids === []) {
            return [];
        }

        $query = <<<SQL
SELECT c.id::text AS id, c.name, cs.name AS series_name, c.date_from, c.is_draft
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id IN (:ids)
ORDER BY COALESCE(c.date_from, c.created_at::date) DESC NULLS LAST, c.id DESC
LIMIT :limit
SQL;

        /** @var list<array{id: string, name: string, series_name: null|string, date_from: null|string, is_draft: bool}> $rows */
        $rows = $this->database->executeQuery(
            $query,
            ['ids' => $ids, 'limit' => $limit],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): EventToCopy => new EventToCopy(
            competitionId: $row['id'],
            name: $row['name'],
            seriesName: $row['series_name'],
            dateFrom: $row['date_from'] !== null ? new DateTimeImmutable($row['date_from']) : null,
            isDraft: (bool) $row['is_draft'],
        ), $rows);
    }
}
