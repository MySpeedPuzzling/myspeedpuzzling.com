<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionEvent;

/**
 * @phpstan-import-type CompetitionEventDatabaseRow from CompetitionEvent
 */
readonly final class GetWjpcEvents
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * All World Jigsaw Puzzle Championship editions, newest first - publicly visible one-time events only (never a draft,
     * IsCompetitionPubliclyVisible).
     *
     * @return array<CompetitionEvent>
     */
    public function allEditions(): array
    {
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT c.*
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE {$visible}
    AND c.series_id IS NULL
    AND (
        c.name ILIKE '%world jigsaw puzzle championship%'
        OR c.name ILIKE '%wjpc%'
        OR c.shortcut ILIKE '%wjpc%'
        OR c.slug ILIKE '%wjpc%'
        OR c.slug ILIKE '%world-jigsaw-puzzle-championship%'
    )
ORDER BY c.date_from DESC NULLS LAST;
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function (array $row): CompetitionEvent {
            /** @var CompetitionEventDatabaseRow $row */
            return CompetitionEvent::fromDatabaseRow($row);
        }, $data);
    }
}
