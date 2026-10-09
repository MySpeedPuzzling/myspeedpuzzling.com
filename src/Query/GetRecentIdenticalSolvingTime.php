<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * The add handler's safety net (docs/features/duplicate-results.md, Layer 1): a result the same tracker saved a
 * moment ago that is identical in every field to the one being saved. Measured on production, nobody types a
 * genuinely new result that fast - within 10 s it is the same form or API call sent again (no JS, an old open
 * form, an API client without an Idempotency-Key).
 *
 * One lookup on custom_pst_player_puzzle_type (player_id, puzzle_id, …).
 */
readonly final class GetRecentIdenticalSolvingTime
{
    public const int WINDOW_SECONDS = 10;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|string $teamCompositionKey TeamComposition key of the group, null for solo
     * @param null|string $roundId Only an explicitly chosen round - otherwise the round follows from competition,
     *                             puzzle and group, which are compared already
     * @param null|string $seriesId A series pick (docs/features/events-page/high-frequency-series.md): compared instead
     *                              of the competition - its edition is derived, the same save may have been matched
     *                              differently a moment ago
     */
    public function savedBy(
        string $playerId,
        string $puzzleId,
        int $secondsToSolve,
        null|DateTimeImmutable $finishedAt,
        null|string $teamCompositionKey,
        null|string $competitionId,
        null|string $roundId,
        bool $firstAttempt,
        bool $unboxed,
        null|string $comment,
        bool $hasPhoto,
        null|string $seriesId = null,
    ): null|string {
        $query = <<<SQL
SELECT pst.id
FROM puzzle_solving_time pst
LEFT JOIN puzzling_team team ON team.id = pst.puzzling_team_id
WHERE pst.player_id = :playerId
    AND pst.puzzle_id = :puzzleId
    AND pst.tracked_at >= :savedSince
    AND pst.seconds_to_solve = :secondsToSolve
    AND CAST(pst.finished_at AS DATE) IS NOT DISTINCT FROM CAST(:finishedOn AS DATE)
    AND team.composition_key IS NOT DISTINCT FROM CAST(:teamKey AS VARCHAR)
    AND (
        (CAST(:seriesId AS UUID) IS NOT NULL AND pst.competition_series_id = CAST(:seriesId AS UUID))
        OR (CAST(:seriesId AS UUID) IS NULL AND pst.competition_series_id IS NULL AND pst.competition_id IS NOT DISTINCT FROM CAST(:competitionId AS UUID))
    )
    AND (CAST(:roundId AS UUID) IS NULL OR pst.competition_round_id = CAST(:roundId AS UUID))
    AND pst.first_attempt = :firstAttempt
    AND pst.unboxed = :unboxed
    AND pst.comment IS NOT DISTINCT FROM CAST(:comment AS TEXT)
    AND (pst.finished_puzzle_photo IS NOT NULL) = :hasPhoto
ORDER BY pst.tracked_at DESC
LIMIT 1
SQL;

        $timeId = $this->database->fetchOne(
            $query,
            [
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
                'savedSince' => $this->clock->now()->modify('-' . self::WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
                'secondsToSolve' => $secondsToSolve,
                'finishedOn' => $finishedAt?->format('Y-m-d'),
                'teamKey' => $teamCompositionKey,
                'competitionId' => $competitionId,
                'seriesId' => $seriesId,
                'roundId' => $roundId,
                'firstAttempt' => $firstAttempt,
                'unboxed' => $unboxed,
                'comment' => $comment,
                'hasPhoto' => $hasPhoto,
            ],
            [
                'firstAttempt' => ParameterType::BOOLEAN,
                'unboxed' => ParameterType::BOOLEAN,
                'hasPhoto' => ParameterType::BOOLEAN,
            ],
        );

        return is_string($timeId) ? $timeId : null;
    }
}
