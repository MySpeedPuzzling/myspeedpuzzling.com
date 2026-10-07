<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Rounds whose official results are on their public page right now - an event approved after its organiser published
 * results tells the players then (ApproveCompetitionHandler, ApproveCompetitionSeriesHandler). Write-side lookup.
 */
readonly final class GetRoundsWithPublishedOfficialResults
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string> round ids
     */
    public function ofCompetition(string $competitionId): array
    {
        /** @var list<string> $roundIds */
        $roundIds = $this->database->fetchFirstColumn(
            'SELECT id FROM competition_round WHERE competition_id = :competitionId AND results_published_at IS NOT NULL ORDER BY starts_at, id',
            ['competitionId' => $competitionId],
        );

        return $roundIds;
    }

    /**
     * Of every edition of the series.
     *
     * @return list<string> round ids
     */
    public function ofSeries(string $seriesId): array
    {
        /** @var list<string> $roundIds */
        $roundIds = $this->database->fetchFirstColumn(
            <<<SQL
SELECT cr.id
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
WHERE c.series_id = :seriesId
    AND cr.results_published_at IS NOT NULL
ORDER BY cr.starts_at, cr.id
SQL,
            ['seriesId' => $seriesId],
        );

        return $roundIds;
    }
}
