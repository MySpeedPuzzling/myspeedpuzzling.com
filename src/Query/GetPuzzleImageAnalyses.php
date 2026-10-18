<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PuzzleImageAnalysisResult;
use SpeedPuzzling\Web\Services\PuzzleImageAnalysisRecorder;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * The stored analyses of puzzles' box images, payloads decoded (docs/features/internal-api.md, "Puzzle image
 * analysis") - for the internal API and for the pages that will show them (Insights). A puzzle whose image is hidden
 * now (a secret competition puzzle, a placeholder before its reveal) has none here, whatever is stored for it.
 */
readonly final class GetPuzzleImageAnalyses
{
    public const int DEFAULT_PAGE_LIMIT = 500;

    // Rows per page at most - an embedding row is ~3-6 KB of JSON
    public const int MAX_PAGE_LIMIT = 1000;

    // Bytes of results per page at most (the row crossing it is the last one) - keeps a page of big payloads a few MB
    public const int MAX_PAGE_BYTES = 8_000_000;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Every analysis of the listed puzzles in one statement, keyed by the lower-case puzzle id and the analyzer -
     * puzzles without any (or with a hidden image, or unknown) are absent. `current` tells an analysis of the
     * puzzle's present picture from one of an older picture; pass $onlyCurrent to get the current ones only.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, array<string, PuzzleImageAnalysisResult>>
     */
    public function forPuzzles(array $puzzleIds, bool $onlyCurrent = false): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        $current = self::sqlCurrent();
        $onlyCurrentCondition = $onlyCurrent ? "AND {$current}" : '';

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    a.puzzle_id,
    a.analyzer,
    a.analyzer_version,
    a.image_fingerprint,
    {$current} AS current,
    a.analyzed_at,
    a.received_at,
    a.result
FROM puzzle_image_analysis a
JOIN puzzle p ON p.id = a.puzzle_id
WHERE a.puzzle_id IN (:puzzleIds)
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
    {$onlyCurrentCondition}
ORDER BY a.puzzle_id, a.analyzer
SQL,
            [
                'puzzleIds' => $puzzleIds,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
            [
                'puzzleIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $analyses = [];

        foreach ($rows as $row) {
            $analysis = PuzzleImageAnalysisResult::fromDatabaseRow($row);

            if ($analysis !== null) {
                $analyses[strtolower($analysis->puzzleId)][$analysis->analyzer->value] = $analysis;
            }
        }

        return $analyses;
    }

    /**
     * Every analysis of one puzzle, keyed by the analyzer.
     *
     * @return array<string, PuzzleImageAnalysisResult>
     *
     * @throws PuzzleNotFound unknown puzzle, or its image hidden now
     */
    public function ofPuzzle(string $puzzleId): array
    {
        $puzzleId = strtolower($puzzleId);

        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound(PuzzleImageAnalysisRecorder::NOT_FOUND_MESSAGE);
        }

        $shown = $this->database->fetchOne(
            <<<SQL
SELECT 1
FROM puzzle p
WHERE p.id = :puzzleId
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
SQL,
            [
                'puzzleId' => $puzzleId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        if ($shown === false) {
            throw new PuzzleNotFound(PuzzleImageAnalysisRecorder::NOT_FOUND_MESSAGE);
        }

        return $this->forPuzzles([$puzzleId])[$puzzleId] ?? [];
    }

    /**
     * Every stored analysis of one analyzer, a page at a time in puzzle id order (keyset: `after` = the last puzzle id
     * of the previous page) - for the pipeline reading results back in bulk (all embeddings to compute neighbours).
     * Walks the unique (analyzer, puzzle_id) index. A page ends after `limit` rows or once its results pass $maxBytes,
     * whichever comes first - `nextCursor` is set either way.
     *
     * @return array{items: list<PuzzleImageAnalysisResult>, nextCursor: null|string}
     */
    public function page(
        ImageAnalyzer $analyzer,
        int $limit,
        null|string $after,
        bool $onlyCurrent,
        int $maxBytes = self::MAX_PAGE_BYTES,
    ): array {
        $limit = max(1, min($limit, self::MAX_PAGE_LIMIT));
        $current = self::sqlCurrent();
        $conditions = $onlyCurrent ? "AND {$current}" : '';
        $parameters = [
            'analyzer' => $analyzer->value,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'limit' => $limit,
            'maxBytes' => max(1, $maxBytes),
        ];

        if ($after !== null) {
            $conditions .= ' AND a.puzzle_id > :after';
            $parameters['after'] = strtolower($after);
        }

        // The page is cut by rows (limit) and by bytes: rows are added while the results before them sum to less than
        // maxBytes (the first row always), so a page of big payloads stays a few MB. The running sum is taken over the
        // `limit` rows only - never over the rest of the table.
        $rows = $this->database->executeQuery(
            <<<SQL
SELECT page.*
FROM (
    SELECT limited.*, SUM(limited.result_bytes) OVER (ORDER BY limited.puzzle_id) AS running_bytes
    FROM (
        SELECT
            a.puzzle_id,
            a.analyzer,
            a.analyzer_version,
            a.image_fingerprint,
            {$current} AS current,
            a.analyzed_at,
            a.received_at,
            a.result,
            octet_length(a.result::text) AS result_bytes
        FROM puzzle_image_analysis a
        JOIN puzzle p ON p.id = a.puzzle_id
        WHERE a.analyzer = :analyzer
            AND (p.hide_until IS NULL OR p.hide_until <= :now)
            AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
            {$conditions}
        ORDER BY a.puzzle_id
        LIMIT :limit
    ) limited
) page
WHERE page.running_bytes - page.result_bytes < CAST(:maxBytes AS BIGINT)
ORDER BY page.puzzle_id
SQL,
            $parameters,
        )->fetchAllAssociative();

        $items = [];
        $lastPuzzleId = null;
        $cutByBytes = false;

        foreach ($rows as $row) {
            $analysis = PuzzleImageAnalysisResult::fromDatabaseRow($row);

            if ($analysis !== null) {
                $items[] = $analysis;
                $lastPuzzleId = $analysis->puzzleId;
            }

            $cutByBytes = is_numeric($row['running_bytes']) && (int) $row['running_bytes'] >= $parameters['maxBytes'];
        }

        return [
            'items' => $items,
            'nextCursor' => count($rows) === $limit || $cutByBytes ? $lastPuzzleId : null,
        ];
    }

    public function one(string $puzzleId, ImageAnalyzer $analyzer): null|PuzzleImageAnalysisResult
    {
        return $this->forPuzzles([$puzzleId])[strtolower($puzzleId)][$analyzer->value] ?? null;
    }

    /**
     * True when the analysis (alias a) describes the image the puzzle (alias p) has now
     */
    private static function sqlCurrent(): string
    {
        return sprintf('COALESCE(a.image_fingerprint = %s, false)', PuzzleImageFingerprint::sql('p.image'));
    }
}
