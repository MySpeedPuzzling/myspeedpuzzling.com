<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified;
use SpeedPuzzling\Web\Message\RecalculateDerivedMetricsForPuzzle;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleDifficultyCalculator;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private PlayerBaselineCalculator $baselineCalculator,
        private PuzzleDifficultyCalculator $difficultyCalculator,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(PuzzleSolved|PuzzleSolvingTimeModified|PuzzleSolvingTimeDeleted $event): void
    {
        $puzzleId = $event->puzzleId->toString();

        if ($event instanceof PuzzleSolvingTimeDeleted) {
            $playerId = $event->playerId->toString();
            $piecesCount = $event->piecesCount;
        } else {
            $row = $this->connection->fetchAssociative("
                SELECT pst.player_id, p.pieces_count, pst.puzzling_type
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.id = :timeId
            ", ['timeId' => $event->puzzleSolvingTimeId->toString()]);

            if ($row === false) {
                return;
            }

            // Only solo solves affect intelligence metrics
            if ($row['puzzling_type'] !== PuzzlingType::Solo->value) {
                return;
            }

            /** @var string $playerId */
            $playerId = $row['player_id'];
            /** @var int|string $rawPiecesCount */
            $rawPiecesCount = $row['pieces_count'];
            $piecesCount = (int) $rawPiecesCount;
        }

        $now = $this->clock->now();

        // Tier 1: Recalculate player baseline for the affected piece count
        $baseline = $this->baselineCalculator->calculateForPlayer($playerId, $piecesCount);

        if ($baseline !== null) {
            $this->upsertBaseline($playerId, $piecesCount, $baseline['baseline_seconds'], $baseline['qualifying_count'], $now);
        }

        // Tier 1: Recalculate puzzle difficulty
        $difficulty = $this->difficultyCalculator->calculateForPuzzle($puzzleId);
        $this->upsertDifficulty($puzzleId, $difficulty, $now);

        // Tier 2: Dispatch async derived metrics recalculation
        if ($difficulty['difficulty_score'] !== null) {
            $this->messageBus->dispatch(new RecalculateDerivedMetricsForPuzzle(Uuid::fromString($puzzleId)));
        }
    }

    private function upsertBaseline(string $playerId, int $piecesCount, int $baselineSeconds, int $qualifyingCount, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("
            INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at)
            VALUES (gen_random_uuid(), :playerId, :piecesCount, :baselineSeconds, :qualifyingCount, 'direct', :now)
            ON CONFLICT (player_id, pieces_count) DO UPDATE SET
                baseline_seconds = EXCLUDED.baseline_seconds,
                qualifying_solves_count = EXCLUDED.qualifying_solves_count,
                baseline_type = EXCLUDED.baseline_type,
                computed_at = EXCLUDED.computed_at
        ", [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'baselineSeconds' => $baselineSeconds,
            'qualifyingCount' => $qualifyingCount,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array{difficulty_score: float|null, difficulty_tier: DifficultyTier|null, confidence: MetricConfidence, sample_size: int, indices_p25: float|null, indices_p75: float|null} $result
     */
    private function upsertDifficulty(string $puzzleId, array $result, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("
            INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, indices_p25, indices_p75, computed_at)
            VALUES (:puzzleId, :score, :tier, :confidence, :sampleSize, :indicesP25, :indicesP75, :now)
            ON CONFLICT (puzzle_id) DO UPDATE SET
                difficulty_score = EXCLUDED.difficulty_score,
                difficulty_tier = EXCLUDED.difficulty_tier,
                confidence = EXCLUDED.confidence,
                sample_size = EXCLUDED.sample_size,
                indices_p25 = EXCLUDED.indices_p25,
                indices_p75 = EXCLUDED.indices_p75,
                computed_at = EXCLUDED.computed_at
        ", [
            'puzzleId' => $puzzleId,
            'score' => $result['difficulty_score'],
            'tier' => $result['difficulty_tier']?->value,
            'confidence' => $result['confidence']->value,
            'sampleSize' => $result['sample_size'],
            'indicesP25' => $result['indices_p25'],
            'indicesP75' => $result['indices_p75'],
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
