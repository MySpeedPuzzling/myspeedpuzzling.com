<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Where the events of a results import went - each one's page and its results page (the round the import keeps, the
 * first by start). For the import's result and history on the results import page; a deleted event is left out.
 */
readonly final class GetResultsImportEventLinks
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $competitionIds
     * @return array<string, array{name: string, slug: null|string, round_slug: null|string, round_category: null|string}> by competition id (lower-case)
     */
    public function byCompetitionIds(array $competitionIds): array
    {
        $competitionIds = array_values(array_unique(array_map(strtolower(...), array_filter($competitionIds, Uuid::isValid(...)))));

        if ($competitionIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT c.id, c.name, c.slug,
    first_round.slug AS round_slug,
    -- The round's category (solo / duo / team) names what the import's result counts: "Team results - new: 2"
    first_round.category AS round_category
FROM competition c
LEFT JOIN LATERAL (
    SELECT cr.slug, cr.category FROM competition_round cr WHERE cr.competition_id = c.id ORDER BY cr.starts_at, cr.id LIMIT 1
) first_round ON true
WHERE c.id = ANY(CAST(:ids AS uuid[]))
SQL;

        /** @var list<array{id: string, name: string, slug: null|string, round_slug: null|string, round_category: null|string}> $rows */
        $rows = $this->database->executeQuery($query, ['ids' => '{' . implode(',', $competitionIds) . '}'])->fetchAllAssociative();

        $links = [];

        foreach ($rows as $row) {
            $links[strtolower($row['id'])] = ['name' => $row['name'], 'slug' => $row['slug'], 'round_slug' => $row['round_slug'], 'round_category' => $row['round_category']];
        }

        return $links;
    }
}
