<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PuzzleDifficultyEstimateResult;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * The picture-based difficulty estimates of puzzles (PuzzleDifficultyEstimate, docs/features/internal-api.md "Puzzle
 * difficulty labels and estimates") for the app - the image prior of the predictions (implementation-plan.md §5.4).
 * Only an estimate of the picture a puzzle has now counts (its image changed = no estimate until the pipeline made a
 * new one), and a secret puzzle or picture (hide_until / hide_image_until ahead) has none, whatever is stored. With
 * the `artwork` kind of the same picture (ImageKind - unknown without one), the other half of the key of
 * ImageEstimateGate::passesFor().
 */
readonly final class GetPuzzleDifficultyEstimates
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * One statement for any number of puzzles, keyed by the lower-case puzzle id - puzzles without a usable estimate
     * (none stored, another picture, secret, unknown id) are absent.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, PuzzleDifficultyEstimateResult>
     */
    public function forPuzzles(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        $fingerprint = PuzzleImageFingerprint::sql('p.image');

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    e.puzzle_id,
    e.model,
    e.model_version,
    e.log_difficulty,
    e.log_p10,
    e.log_p90,
    e.out_of_fold,
    e.training_size,
    e.image_fingerprint,
    e.computed_at,
    e.received_at,
    art.result->>'kind' AS artwork_kind
FROM puzzle_difficulty_estimate e
JOIN puzzle p ON p.id = e.puzzle_id
LEFT JOIN puzzle_image_analysis art ON art.puzzle_id = e.puzzle_id AND art.analyzer = 'artwork' AND art.image_fingerprint = e.image_fingerprint
WHERE e.puzzle_id IN (:puzzleIds)
    AND e.image_fingerprint = {$fingerprint}
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
SQL,
            [
                'puzzleIds' => $puzzleIds,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
            [
                'puzzleIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $estimates = [];

        foreach ($rows as $row) {
            $estimate = PuzzleDifficultyEstimateResult::fromDatabaseRow($row);
            $estimates[strtolower($estimate->puzzleId)] = $estimate;
        }

        return $estimates;
    }
}
