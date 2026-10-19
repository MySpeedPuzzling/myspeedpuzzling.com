<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RepeatResiduals;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PiecesGroup;

/**
 * Every repeat with a stored personal prediction, each with the player's repeat spread right before it (their last
 * 20 repeat residuals, RepeatResiduals) - what the daily fit calibrates repeat windows on and the evaluation measures
 * them with. A repeat's centre (Holt) is the same in every model version, so the stored predictions are the centres.
 */
final class RepeatHistory
{
    /** @var list<float> */
    public array $solved = [];
    /** @var list<int> */
    public array $seconds = [];
    /** @var list<int> */
    public array $predicted = [];
    /** @var list<float> the player's repeat spread before the solve */
    public array $spread = [];
    /** @var list<PiecesGroup> */
    public array $group = [];

    /**
     * @param float $from only repeats solved from then on are kept (the spread before them still comes from the whole history)
     */
    public static function load(Connection $connection, float $from): self
    {
        $history = new self();

        $rows = $connection->executeQuery("
            SELECT pst.player_id, p.pieces_count, pst.seconds_to_solve, pst.predicted_seconds,
                EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.unboxed = false
                AND pst.seconds_to_solve > 0
                AND pst.predictable = true
                AND pst.prediction_method = 'personal'
                AND pst.predicted_seconds > 0
            ORDER BY pst.player_id, COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
        ")->iterateAssociative();

        $player = null;
        $residuals = [];

        foreach ($rows as $row) {
            /** @var array{player_id: string, pieces_count: int|string, seconds_to_solve: int|string, predicted_seconds: int|string, solved_ts: float|int|string} $row */
            if ($row['player_id'] !== $player) {
                $player = $row['player_id'];
                $residuals = [];
            }

            $seconds = (int) $row['seconds_to_solve'];
            $predicted = (int) $row['predicted_seconds'];
            $solved = (float) $row['solved_ts'];

            if ($solved >= $from) {
                $history->solved[] = $solved;
                $history->seconds[] = $seconds;
                $history->predicted[] = $predicted;
                $history->spread[] = TimePredictionCalculator::playerSpread($residuals);
                $history->group[] = PiecesGroup::fromPiecesCount((int) $row['pieces_count']);
            }

            $residuals[] = RepeatResiduals::residual($seconds, $predicted);

            if (count($residuals) > RepeatResiduals::WINDOW) {
                array_shift($residuals);
            }
        }

        return $history;
    }

    public function count(): int
    {
        return count($this->solved);
    }
}
