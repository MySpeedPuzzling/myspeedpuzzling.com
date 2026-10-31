<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\PictureFactors;
use SpeedPuzzling\Web\Value\TasteModel;

/**
 * Keeps candidate_picture_projection current (entity CandidatePictureProjection): for every puzzle whose current picture
 * has picture factors of the taste model's basis (the estimate of its fingerprint, not a secret puzzle - the readers'
 * rule, StatisticalInputsSql::imageEstimateJoins()), W's rows times the factors (TasteModel::factorSums()) with what
 * they were computed from. A documented bulk operation: rows that changed are upserted, rows of pictures without such
 * factors deleted - in one statement each, the rows never loaded as entities. Run by the candidate's daily fit (W
 * changes, in its transaction, with the fit's moment as taste_fitted_at) and by its 15-minute evidence refresh (pushed
 * estimates). The only reader, the Puzzle Picker's SQL centre, reads a row only while it is current, so a run that is
 * late or failed costs speed, never a wrong number.
 */
readonly final class CandidatePictureProjections
{
    private const int CHUNK = 2000;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return int rows written or deleted
     */
    public function sync(CandidateModelParameters $parameters, DateTimeImmutable $fittedAt, DateTimeImmutable $now): int
    {
        $model = $parameters->taste;

        if ($model === null || count($model->weights) !== TasteModel::RANK) {
            return (int) $this->connection->executeStatement('DELETE FROM candidate_picture_projection');
        }

        /** @var array<string, list<float|string>> $stored */
        $stored = [];

        // PostgreSQL prints a double as its shortest round trip, so (float) of it is the stored double itself
        foreach ($this->connection->iterateNumeric('SELECT puzzle_id, image_fingerprint, estimate_received_at, factors_version, taste_fitted_at, factor_sum1, factor_sum2, factor_sum3, factor_sum4 FROM candidate_picture_projection') as $row) {
            /** @var array{0: string, 1: string, 2: string, 3: string, 4: string, 5: float|string, 6: float|string, 7: float|string, 8: float|string} $row */
            $stored[$row[0]] = [$row[1], $row[2], $row[3], $row[4], (float) $row[5], (float) $row[6], (float) $row[7], (float) $row[8]];
        }

        $fitted = $fittedAt->format('Y-m-d H:i:s');
        $rows = $this->connection->executeQuery(
            '
            SELECT p.id, img.image_fingerprint, img.received_at, img.picture_factors_version, CAST(img.picture_factors AS text) AS picture_factors
            FROM puzzle p
            ' . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: false) . '
            WHERE img.puzzle_id IS NOT NULL AND img.picture_factors_version = :version
            ',
            ['now' => $now->format('Y-m-d H:i:s'), 'version' => $model->factorsVersion],
        )->iterateAssociative();

        $changed = [];
        $current = [];
        $written = 0;

        foreach ($rows as $row) {
            /** @var array{id: string, image_fingerprint: string, received_at: string, picture_factors_version: string, picture_factors: null|string} $row */
            $picture = PictureFactors::fromColumns($row['picture_factors'], $row['picture_factors_version'], null);

            if ($picture?->factors === null) {
                continue;
            }

            $sums = TasteModel::factorSums($model->weights, TasteModel::features($picture, 500, $model->factorsVersion));
            $values = [$row['image_fingerprint'], $row['received_at'], $row['picture_factors_version'], $fitted, ...$sums];
            $current[$row['id']] = true;

            if (($stored[$row['id']] ?? null) === $values) {
                continue;
            }

            $changed[] = [$row['id'], ...$values];

            if (count($changed) >= self::CHUNK) {
                $written += $this->upsert($changed, $now);
                $changed = [];
            }
        }

        $written += $this->upsert($changed, $now);
        $gone = array_values(array_diff(array_keys($stored), array_keys($current)));

        foreach (array_chunk($gone, self::CHUNK) as $chunk) {
            $written += (int) $this->connection->executeStatement(
                'DELETE FROM candidate_picture_projection WHERE puzzle_id = ANY (CAST(:puzzles AS uuid[]))',
                ['puzzles' => '{' . implode(',', $chunk) . '}'],
            );
        }

        return $written;
    }

    /**
     * @param list<list<float|string>> $rows puzzle id, fingerprint, received at, factors version, fitted at, the 4 sums
     */
    private function upsert(array $rows, DateTimeImmutable $now): int
    {
        if ($rows === []) {
            return 0;
        }

        // Each double in JSON as its shortest round trip (json_encode), which float8 reads back as the very same double
        $this->connection->executeStatement('
            INSERT INTO candidate_picture_projection (puzzle_id, image_fingerprint, estimate_received_at, factors_version, taste_fitted_at, factor_sum1, factor_sum2, factor_sum3, factor_sum4, computed_at)
            SELECT CAST(r->>0 AS uuid), r->>1, CAST(r->>2 AS timestamp), r->>3, CAST(r->>4 AS timestamp),
                CAST(r->>5 AS double precision), CAST(r->>6 AS double precision), CAST(r->>7 AS double precision), CAST(r->>8 AS double precision), CAST(:now AS timestamp)
            FROM jsonb_array_elements(CAST(:rows AS jsonb)) r
            ON CONFLICT (puzzle_id) DO UPDATE SET
                image_fingerprint = EXCLUDED.image_fingerprint,
                estimate_received_at = EXCLUDED.estimate_received_at,
                factors_version = EXCLUDED.factors_version,
                taste_fitted_at = EXCLUDED.taste_fitted_at,
                factor_sum1 = EXCLUDED.factor_sum1,
                factor_sum2 = EXCLUDED.factor_sum2,
                factor_sum3 = EXCLUDED.factor_sum3,
                factor_sum4 = EXCLUDED.factor_sum4,
                computed_at = EXCLUDED.computed_at
        ', [
            'rows' => json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'now' => $now->format('Y-m-d H:i:s'),
        ]);

        return count($rows);
    }
}
