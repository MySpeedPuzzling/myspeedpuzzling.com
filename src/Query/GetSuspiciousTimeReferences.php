<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Value\PaceReference;
use SpeedPuzzling\Web\Value\PaceReferences;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspicionPiecesRange;

/**
 * The community's pace per piece-count range and puzzling type (docs/features/suspicious-time-review.md, "The
 * expected time"): the median and the 99.9th percentile of pieces per minute over every non-suspicious result with a
 * time, where there are enough of them. Makes piece counts comparable for the pace fallback, is the bar for a player
 * without times of their own and gives the slow floor of pair/team results.
 */
readonly final class GetSuspiciousTimeReferences
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Computed from the results now - one pass over the results (~1 s on a copy of production), the scan only.
     */
    public function compute(): PaceReferences
    {
        $range = SuspicionPiecesRange::sql('p.pieces_count');

        $query = <<<SQL
SELECT
    {$range} AS pieces_range,
    pst.puzzling_type,
    COUNT(*) AS sample_size,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY p.pieces_count * 60.0 / pst.seconds_to_solve) AS median_ppm,
    percentile_cont(:topPercentile) WITHIN GROUP (ORDER BY p.pieces_count * 60.0 / pst.seconds_to_solve) AS p999_ppm
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.seconds_to_solve > 0
    AND pst.suspicious = false
    AND p.pieces_count > 0
GROUP BY 1, 2
HAVING COUNT(*) >= :minSample
SQL;

        /** @var list<array{pieces_range: string, puzzling_type: string, sample_size: int|string, median_ppm: float|string, p999_ppm: float|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'topPercentile' => SuspiciousTimeClassifier::REFERENCE_TOP_PERCENTILE,
            'minSample' => SuspiciousTimeClassifier::REFERENCE_MIN_SAMPLE,
        ]);

        return self::references($rows);
    }

    /**
     * What the last scan stored - for a check outside the scan (the add/edit form).
     */
    public function stored(): PaceReferences
    {
        /** @var list<array{pieces_range: string, puzzling_type: string, sample_size: int|string, median_ppm: float|string, p999_ppm: float|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT pieces_range, puzzling_type, sample_size, median_ppm, p999_ppm FROM suspicious_time_reference',
        );

        return self::references($rows);
    }

    /**
     * @param list<array{pieces_range: string, puzzling_type: string, sample_size: int|string, median_ppm: float|string, p999_ppm: float|string}> $rows
     */
    private static function references(array $rows): PaceReferences
    {
        return new PaceReferences(array_map(
            static fn (array $row): PaceReference => new PaceReference(
                piecesRange: SuspicionPiecesRange::from($row['pieces_range']),
                puzzlingType: PuzzlingType::from($row['puzzling_type']),
                medianPpm: (float) $row['median_ppm'],
                p999Ppm: (float) $row['p999_ppm'],
                sampleSize: (int) $row['sample_size'],
            ),
            $rows,
        ));
    }
}
