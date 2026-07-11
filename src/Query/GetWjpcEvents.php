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
     * All World Jigsaw Puzzle Championship editions, newest first.
     *
     * @return array<CompetitionEvent>
     */
    public function allEditions(): array
    {
        $query = <<<SQL
SELECT *
FROM competition
WHERE approved_at IS NOT NULL
    AND rejected_at IS NULL
    AND series_id IS NULL
    AND (
        name ILIKE '%world jigsaw puzzle championship%'
        OR name ILIKE '%wjpc%'
        OR shortcut ILIKE '%wjpc%'
        OR slug ILIKE '%wjpc%'
        OR slug ILIKE '%world-jigsaw-puzzle-championship%'
    )
ORDER BY date_from DESC NULLS LAST;
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
