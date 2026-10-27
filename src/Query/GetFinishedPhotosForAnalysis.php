<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\FinishedPhotoForAnalysis;
use SpeedPuzzling\Web\Results\FinishedPhotosOfPuzzle;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisStatus;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * The image pipeline's finished-photo work list (GET /internal-api/finished-photos, docs/features/insights/
 * finished-photos.md): puzzles with a shown image - never one hidden by hide_until / hide_image_until - and at least
 * one finished-puzzle photo of a public player (a private player's photo never leaves the app), in puzzle id order, a
 * page of `limit` puzzles at a time (keyset: `after`). Per puzzle at most `perPuzzle` photos, the oldest results first
 * (a stable choice: a new photo joins only while the puzzle has fewer). A photo is known by its fingerprint only
 * (PuzzleImageFingerprint of the stored path - no player, no solving time ever leaves this query).
 *
 * `status` filters the photos: `missing` = no analysis by the analyzer, `stale` = none, or one of another photo,
 * another catalogue picture or another analyzer version (when one is asked for), `all` = every listed photo. A puzzle
 * without a photo left is left out of the page, but counts as scanned - so a page may hold fewer puzzles than `limit`,
 * even none: follow `nextCursor` (the last scanned puzzle) until it is null.
 *
 * Two statements a page: the puzzles (one EXISTS probe of puzzle_solving_time's puzzle index per puzzle walked), and
 * their photos with what is stored for them. Whether a result is suspicious does not matter here - a photo of the
 * assembled puzzle is one whatever its time. Once a day for one pipeline: no index of its own.
 */
readonly final class GetFinishedPhotosForAnalysis
{
    public const int DEFAULT_LIMIT = 200;

    public const int MAX_LIMIT = 1000;

    public const int DEFAULT_PER_PUZZLE = 4;

    public const int MAX_PER_PUZZLE = 20;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{items: list<FinishedPhotosOfPuzzle>, nextCursor: null|string}
     */
    public function page(
        PuzzleImageAnalysisStatus $status,
        ImageAnalyzer $analyzer,
        null|string $version,
        int $limit,
        int $perPuzzle,
        null|string $after,
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $fingerprint = PuzzleImageFingerprint::sql('p.image');
        $parameters = ['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'limit' => $limit];
        $afterCondition = '';

        if ($after !== null) {
            $afterCondition = 'AND p.id > :after';
            $parameters['after'] = strtolower($after);
        }

        $puzzles = $this->database->executeQuery(
            <<<SQL
SELECT p.id AS puzzle_id, p.pieces_count, p.image, {$fingerprint} AS image_fingerprint
FROM puzzle p
WHERE p.image IS NOT NULL
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
    {$afterCondition}
    AND EXISTS (
        SELECT 1
        FROM puzzle_solving_time pst
        JOIN player pl ON pl.id = pst.player_id
        WHERE pst.puzzle_id = p.id
            AND pst.finished_puzzle_photo IS NOT NULL
            AND pl.is_private = false
    )
ORDER BY p.id
LIMIT :limit
SQL,
            $parameters,
        )->fetchAllAssociative();

        $puzzleIds = array_values(array_filter(array_column($puzzles, 'puzzle_id'), is_string(...)));
        $photos = $this->photosOf($puzzleIds, $analyzer, max(1, min($perPuzzle, self::MAX_PER_PUZZLE)));
        $items = [];

        foreach ($puzzles as $row) {
            $puzzleId = $row['puzzle_id'];
            $piecesCount = $row['pieces_count'];
            $image = $row['image'];
            $imageFingerprint = $row['image_fingerprint'];
            assert(is_string($puzzleId) && is_int($piecesCount) && is_string($image) && is_string($imageFingerprint));

            $todo = array_values(array_filter(
                $photos[$puzzleId] ?? [],
                static fn (FinishedPhotoForAnalysis $photo): bool => self::matches($photo, $status, $imageFingerprint, $version),
            ));

            if ($todo !== []) {
                $items[] = new FinishedPhotosOfPuzzle($puzzleId, $piecesCount, $image, $imageFingerprint, $todo);
            }
        }

        $last = $puzzleIds !== [] ? $puzzleIds[array_key_last($puzzleIds)] : null;

        return [
            'items' => $items,
            'nextCursor' => count($puzzles) === $limit ? $last : null,
        ];
    }

    /**
     * The stored path of one current finished photo of a puzzle whose image is shown, by the photo's fingerprint - null
     * when there is none (another photo now, a private player, a hidden puzzle). For the download endpoint only: the
     * path names the player and never leaves the app.
     */
    public function photoPath(string $puzzleId, string $photoFingerprint): null|string
    {
        if (Uuid::isValid($puzzleId) === false || preg_match('/^[0-9a-f]{' . PuzzleImageFingerprint::LENGTH . '}$/', $photoFingerprint) !== 1) {
            return null;
        }

        $photo = PuzzleImageFingerprint::sql('pst.finished_puzzle_photo');

        $path = $this->database->executeQuery(
            <<<SQL
SELECT pst.finished_puzzle_photo
FROM puzzle_solving_time pst
JOIN player pl ON pl.id = pst.player_id
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.puzzle_id = :puzzleId
    AND pst.finished_puzzle_photo IS NOT NULL
    AND {$photo} = :photoFingerprint
    AND pl.is_private = false
    AND p.image IS NOT NULL
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
ORDER BY pst.id
LIMIT 1
SQL,
            [
                'puzzleId' => strtolower($puzzleId),
                'photoFingerprint' => $photoFingerprint,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        )->fetchOne();

        return is_string($path) ? $path : null;
    }

    private static function matches(FinishedPhotoForAnalysis $photo, PuzzleImageAnalysisStatus $status, string $imageFingerprint, null|string $version): bool
    {
        $analysis = $photo->analysis;

        return match ($status) {
            PuzzleImageAnalysisStatus::All => true,
            PuzzleImageAnalysisStatus::Missing => $analysis === null,
            PuzzleImageAnalysisStatus::Stale => $analysis === null
                || $analysis['photoFingerprint'] !== $photo->photoFingerprint
                || $analysis['imageFingerprint'] !== $imageFingerprint
                || ($version !== null && $analysis['analyzerVersion'] !== $version),
        };
    }

    /**
     * @param list<string> $puzzleIds
     *
     * @return array<string, list<FinishedPhotoForAnalysis>>
     */
    private function photosOf(array $puzzleIds, ImageAnalyzer $analyzer, int $perPuzzle): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $photo = PuzzleImageFingerprint::sql('pst.finished_puzzle_photo');

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    ph.puzzle_id,
    ph.photo_fingerprint,
    a.analyzer_version,
    a.photo_fingerprint AS stored_photo_fingerprint,
    a.image_fingerprint AS stored_image_fingerprint,
    a.analyzed_at,
    a.received_at
FROM (
    SELECT
        pst.id,
        pst.puzzle_id,
        {$photo} AS photo_fingerprint,
        row_number() OVER (PARTITION BY pst.puzzle_id ORDER BY pst.id) AS position
    FROM puzzle_solving_time pst
    JOIN player pl ON pl.id = pst.player_id
    WHERE pst.puzzle_id IN (:puzzleIds)
        AND pst.finished_puzzle_photo IS NOT NULL
        AND pl.is_private = false
) ph
LEFT JOIN finished_photo_analysis a ON a.solving_time_id = ph.id AND a.analyzer = :analyzer
WHERE ph.position <= :perPuzzle
ORDER BY ph.puzzle_id, ph.position
SQL,
            ['puzzleIds' => $puzzleIds, 'analyzer' => $analyzer->value, 'perPuzzle' => $perPuzzle],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $photos = [];

        foreach ($rows as $row) {
            $puzzleId = $row['puzzle_id'];
            $photoFingerprint = $row['photo_fingerprint'];
            assert(is_string($puzzleId) && is_string($photoFingerprint));
            $analysis = null;

            if (is_string($row['analyzer_version']) && is_string($row['stored_photo_fingerprint']) && is_string($row['stored_image_fingerprint']) && is_string($row['received_at'])) {
                $analysis = [
                    'analyzerVersion' => $row['analyzer_version'],
                    'photoFingerprint' => $row['stored_photo_fingerprint'],
                    'imageFingerprint' => $row['stored_image_fingerprint'],
                    'analyzedAt' => is_string($row['analyzed_at']) ? new DateTimeImmutable($row['analyzed_at']) : null,
                    'receivedAt' => new DateTimeImmutable($row['received_at']),
                ];
            }

            $photos[$puzzleId][] = new FinishedPhotoForAnalysis($photoFingerprint, $analysis);
        }

        return $photos;
    }
}
