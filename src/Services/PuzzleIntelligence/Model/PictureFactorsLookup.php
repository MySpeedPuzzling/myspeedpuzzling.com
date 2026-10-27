<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Value\PictureFactors;
use SpeedPuzzling\Web\Value\PictureLookalikes;

/**
 * The picture factors, kind and look-alike numbers (v6) of puzzles' current pictures (the estimate of the picture's fingerprint - any fold: the
 * factors are a frozen PCA of the picture's embedding, no solve went into them) - none for a secret puzzle. What the
 * candidate's taste factor reads in the fit, the evaluation and a reconstruction (StatisticalInputsSql::imageEstimateJoins()).
 */
final class PictureFactorsLookup
{
    private const int CHUNK = 5000;

    /**
     * @param null|list<string> $puzzleIds null = every puzzle
     * @return array<string, PictureFactors>
     */
    public static function load(Connection $connection, DateTimeImmutable $now, null|array $puzzleIds = null): array
    {
        $pictures = [];
        $versions = [];

        foreach ($puzzleIds === null ? [null] : array_chunk($puzzleIds, self::CHUNK) as $chunk) {
            $rows = $connection->executeQuery(
                '
                SELECT p.id, CAST(img.picture_factors AS text) AS picture_factors, img.picture_factors_version, art.result->>\'kind\' AS artwork_kind, ' . PictureLookalikes::COLUMNS . '
                FROM puzzle p
                ' . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: false) . PictureLookalikes::JOINS . '
                WHERE img.puzzle_id IS NOT NULL' . ($chunk !== null ? ' AND p.id IN (:puzzleIds)' : ''),
                ['now' => $now->format('Y-m-d H:i:s'), ...($chunk !== null ? ['puzzleIds' => $chunk] : [])],
                $chunk !== null ? ['puzzleIds' => ArrayParameterType::STRING] : [],
            )->iterateAssociative();

            foreach ($rows as $row) {
                /** @var array{id: string, picture_factors: null|string, picture_factors_version: null|string, artwork_kind: null|string} $row */
                $version = $row['picture_factors_version'];
                $picture = PictureFactors::fromColumns($row['picture_factors'], $version !== null ? ($versions[$version] ??= $version) : null, $row['artwork_kind'], PictureLookalikes::fromRow($row));

                if ($picture !== null) {
                    $pictures[$row['id']] = $picture;
                }
            }
        }

        return $pictures;
    }
}
