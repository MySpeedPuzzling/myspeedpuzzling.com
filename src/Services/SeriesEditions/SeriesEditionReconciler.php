<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SeriesEditions;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Query\SeriesEditionMatch;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;

/**
 * Brings every series pick in scope in line with the one rule (SeriesEditionMatch, docs/features/events-page/
 * high-frequency-series.md "Stickiness"), then the round results of the series' editions. Only automatic links and
 * series-level times are looked at - an explicit link (competition_series_id NULL) is never touched:
 *
 * - series-level: linked when the rule finds an edition, else left alone;
 * - an automatic link that still holds under its own kind is kept - a date link moves only when the rule finds a
 *   puzzle match (puzzle beats date);
 * - an automatic link that no longer holds takes the rule's current answer, or goes back to series-level.
 *
 * Runs only after the changes it reacts to are flushed: the postFlush handlers of SeriesEditionsChanged and
 * CompetitionRoundsChanged, and the 15-minute `myspeedpuzzling:reconcile-round-results` cron for every series (the
 * safety net for reveals by time and changes made in SQL). Idempotent.
 */
readonly final class SeriesEditionReconciler
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private RoundResultsReconciler $roundResultsReconciler,
    ) {
    }

    /**
     * One series, or every series (null) - then the round results of every competition too.
     *
     * linked = series-level -> edition, moved = another edition or another kind, released = edition -> series-level
     *
     * @return array{linked: int, moved: int, released: int, roundsLinked: int, roundsUnlinked: int}
     */
    public function reconcile(null|UuidInterface $seriesId = null): array
    {
        $candidates = SeriesEditionMatch::sqlCandidates($seriesId === null ? 'c.series_id IS NOT NULL' : 'c.series_id = CAST(:seriesId AS UUID)');
        $answer = SeriesEditionMatch::sqlAnswer('pick.series_id', 'pick.puzzle_id', 'pick.category', 'pick.solve_day');
        $holds = SeriesEditionMatch::sqlLinkHolds('pick.current_id', 'pick.current_kind', 'pick.series_id', 'pick.puzzle_id', 'pick.category', 'pick.solve_day');
        $pickScope = $seriesId === null ? '' : 'AND pst.competition_series_id = CAST(:seriesId AS UUID)';

        $parameters = [SeriesEditionMatch::NOW_PARAMETER => $this->clock->now()->format(SeriesEditionMatch::DATE_FORMAT)];

        if ($seriesId !== null) {
            $parameters['seriesId'] = $seriesId->toString();
        }

        // A genuine bulk operation - set-based like RoundResultsReconciler, only after flush: one statement for every
        // series pick in scope, whatever the number of editions and picks
        $changes = $this->database->fetchAllAssociative(
            <<<SQL
WITH {$candidates},
pick AS (
    SELECT pst.id,
        pst.competition_series_id AS series_id,
        pst.puzzle_id,
        CAST(pst.puzzling_type AS VARCHAR) AS category,
        CAST(COALESCE(pst.finished_at, pst.tracked_at) AS DATE) AS solve_day,
        pst.competition_id AS current_id,
        CAST(pst.series_edition_match AS VARCHAR) AS current_kind
    FROM puzzle_solving_time pst
    WHERE pst.competition_series_id IS NOT NULL
        {$pickScope}
),
evaluated AS (
    SELECT pick.id,
        pick.current_id,
        pick.current_kind,
        a.competition_id AS answer_id,
        a.kind AS answer_kind,
        COALESCE({$holds}, false) AS holds
    FROM pick
    LEFT JOIN LATERAL ({$answer}) a ON true
),
decided AS (
    SELECT evaluated.id,
        evaluated.current_id,
        CASE WHEN evaluated.holds AND (evaluated.current_kind = 'puzzle' OR evaluated.answer_kind IS DISTINCT FROM 'puzzle')
            THEN evaluated.current_id ELSE evaluated.answer_id END AS new_id,
        CASE WHEN evaluated.holds AND (evaluated.current_kind = 'puzzle' OR evaluated.answer_kind IS DISTINCT FROM 'puzzle')
            THEN evaluated.current_kind ELSE evaluated.answer_kind END AS new_kind
    FROM evaluated
)
UPDATE puzzle_solving_time AS target
SET competition_id = decided.new_id,
    series_edition_match = decided.new_kind,
    competition_round_id = CASE WHEN target.competition_id IS DISTINCT FROM decided.new_id THEN NULL ELSE target.competition_round_id END
FROM decided
WHERE target.id = decided.id
    AND (target.competition_id IS DISTINCT FROM decided.new_id OR target.series_edition_match IS DISTINCT FROM decided.new_kind)
RETURNING CAST(decided.current_id AS VARCHAR) AS current_id, CAST(decided.new_id AS VARCHAR) AS new_id
SQL,
            $parameters,
        );

        $linked = 0;
        $moved = 0;
        $released = 0;

        foreach ($changes as $change) {
            match (true) {
                $change['current_id'] === null => $linked++,
                $change['new_id'] === null => $released++,
                default => $moved++,
            };
        }

        $rounds = $seriesId === null
            ? $this->roundResultsReconciler->reconcile()
            : $this->roundResultsReconciler->reconcileSeries($seriesId);

        return [
            'linked' => $linked,
            'moved' => $moved,
            'released' => $released,
            'roundsLinked' => $rounds['linked'],
            'roundsUnlinked' => $rounds['unlinked'],
        ];
    }

    /**
     * A change of a competition's rounds (CompetitionRoundsChanged): for an edition its series' picks are re-matched
     * first (with the round results of every edition of the series), otherwise its round results as always.
     */
    public function reconcileCompetition(UuidInterface $competitionId): void
    {
        $seriesId = $this->database->fetchOne(
            'SELECT series_id FROM competition WHERE id = :competitionId',
            ['competitionId' => $competitionId->toString()],
        );

        if (is_string($seriesId)) {
            $this->reconcile(Uuid::fromString($seriesId));

            return;
        }

        $this->roundResultsReconciler->reconcile($competitionId);
    }
}
