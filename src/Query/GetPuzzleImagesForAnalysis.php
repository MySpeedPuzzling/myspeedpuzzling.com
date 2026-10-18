<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PuzzleImageForAnalysis;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisStatus;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * The image analysis pipeline's work list (GET /internal-api/puzzle-images, docs/features/internal-api.md "Puzzle
 * image analysis"): puzzles with an image that is shown now - never one hidden by hide_until / hide_image_until (a
 * secret competition puzzle, a placeholder before its reveal) - in puzzle id order, a page at a time (keyset: `after`
 * = the last id of the previous page). Two statements per page: the puzzles, then what is stored for them.
 *
 * The plan is a merge join of the puzzle primary key with the analyses' puzzle_id index, stopping at `limit` matches
 * (measured 2026-10-18 on 41k puzzles × 4 analyzers: a full page of `all` < 1 ms, of `stale` with 2 % to do ~100 ms,
 * the last, empty page of `stale` - every puzzle read once - ~350 ms). Once a day for one pipeline: no index of its own.
 */
readonly final class GetPuzzleImagesForAnalysis
{
    public const int DEFAULT_LIMIT = 500;

    public const int MAX_LIMIT = 1000;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|ImageAnalyzer $analyzer required for `missing` and `stale`
     * @param null|string $version for `stale`: an analysis of another analyzer version is stale too
     *
     * @return array{items: list<PuzzleImageForAnalysis>, nextCursor: null|string}
     */
    public function page(
        PuzzleImageAnalysisStatus $status,
        null|ImageAnalyzer $analyzer,
        null|string $version,
        int $limit,
        null|string $after,
    ): array {
        $fingerprint = PuzzleImageFingerprint::sql('p.image');
        $parameters = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'limit' => max(1, min($limit, self::MAX_LIMIT)),
        ];
        $join = '';
        $conditions = [];

        if ($after !== null) {
            $conditions[] = 'p.id > :after';
            $parameters['after'] = strtolower($after);
        }

        if ($status !== PuzzleImageAnalysisStatus::All && $analyzer !== null) {
            $join = 'LEFT JOIN puzzle_image_analysis a ON a.puzzle_id = p.id AND a.analyzer = :analyzer';
            $parameters['analyzer'] = $analyzer->value;

            if ($status === PuzzleImageAnalysisStatus::Missing) {
                $conditions[] = 'a.id IS NULL';
            } elseif ($version !== null) {
                $conditions[] = "(a.id IS NULL OR a.image_fingerprint <> {$fingerprint} OR a.analyzer_version <> :version)";
                $parameters['version'] = $version;
            } else {
                $conditions[] = "(a.id IS NULL OR a.image_fingerprint <> {$fingerprint})";
            }
        }

        $where = $conditions !== [] ? 'AND ' . implode(' AND ', $conditions) : '';

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    p.id AS puzzle_id,
    p.name,
    p.pieces_count,
    p.image,
    p.image_ratio,
    {$fingerprint} AS image_fingerprint,
    m.id AS manufacturer_id,
    m.name AS manufacturer_name
FROM puzzle p
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
{$join}
WHERE p.image IS NOT NULL
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
    {$where}
ORDER BY p.id
LIMIT :limit
SQL,
            $parameters,
        )->fetchAllAssociative();

        $analyses = $this->analysesOf(array_values(array_filter(array_column($rows, 'puzzle_id'), is_string(...))));
        $items = [];

        foreach ($rows as $row) {
            $puzzleId = $row['puzzle_id'];
            $name = $row['name'];
            $piecesCount = $row['pieces_count'];
            $image = $row['image'];
            $imageFingerprint = $row['image_fingerprint'];
            assert(is_string($puzzleId) && is_string($name) && is_int($piecesCount) && is_string($image) && is_string($imageFingerprint));

            $items[] = new PuzzleImageForAnalysis(
                puzzleId: $puzzleId,
                name: $name,
                piecesCount: $piecesCount,
                manufacturerId: is_string($row['manufacturer_id']) ? $row['manufacturer_id'] : null,
                manufacturerName: is_string($row['manufacturer_name']) ? $row['manufacturer_name'] : null,
                image: $image,
                imageFingerprint: $imageFingerprint,
                imageRatio: is_numeric($row['image_ratio']) ? (float) $row['image_ratio'] : null,
                analyses: $analyses[$puzzleId] ?? [],
            );
        }

        $lastItem = $items !== [] ? $items[array_key_last($items)] : null;

        return [
            'items' => $items,
            'nextCursor' => count($items) === $parameters['limit'] && $lastItem !== null ? $lastItem->puzzleId : null,
        ];
    }

    /**
     * @param list<string> $puzzleIds
     *
     * @return array<string, array<string, array{analyzerVersion: string, imageFingerprint: string, analyzedAt: null|DateTimeImmutable, receivedAt: DateTimeImmutable}>>
     */
    private function analysesOf(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT puzzle_id, analyzer, analyzer_version, image_fingerprint, analyzed_at, received_at
FROM puzzle_image_analysis
WHERE puzzle_id IN (:puzzleIds)
ORDER BY puzzle_id, analyzer
SQL,
            ['puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $analyses = [];

        foreach ($rows as $row) {
            $puzzleId = $row['puzzle_id'];
            $analyzer = $row['analyzer'];
            $analyzerVersion = $row['analyzer_version'];
            $imageFingerprint = $row['image_fingerprint'];
            $receivedAt = $row['received_at'];
            assert(is_string($puzzleId) && is_string($analyzer) && is_string($analyzerVersion) && is_string($imageFingerprint) && is_string($receivedAt));

            $analyses[$puzzleId][$analyzer] = [
                'analyzerVersion' => $analyzerVersion,
                'imageFingerprint' => $imageFingerprint,
                'analyzedAt' => is_string($row['analyzed_at']) ? new DateTimeImmutable($row['analyzed_at']) : null,
                'receivedAt' => new DateTimeImmutable($receivedAt),
            ];
        }

        return $analyses;
    }
}
