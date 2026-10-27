<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\FinishedPhotoAnalysisResult;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * The stored analyses of players' finished-puzzle photos, a page at a time (GET /internal-api/finished-photo-analyses,
 * docs/features/insights/finished-photos.md) - for the image pipeline's derive step (the picture-based difficulty
 * estimate reads them per puzzle). Keyset on the analysis id; never a player or a solving time in the answer, only the
 * puzzle and the photo's fingerprint. Puzzles hidden now (hide_until / hide_image_until) are left out.
 *
 * `current` = the analysis still describes one of the puzzle's photos of a public player (the result carries that
 * photo) and was matched against the puzzle's current catalogue picture. `onlyCurrent` leaves the rest out. A
 * suspicious result's photo is a photo of the puzzle like any other.
 */
readonly final class GetFinishedPhotoAnalyses
{
    public const int DEFAULT_PAGE_LIMIT = 500;

    public const int MAX_PAGE_LIMIT = 1000;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{items: list<FinishedPhotoAnalysisResult>, nextCursor: null|string}
     */
    public function page(ImageAnalyzer $analyzer, int $limit, null|string $after, bool $onlyCurrent): array
    {
        $limit = max(1, min($limit, self::MAX_PAGE_LIMIT));
        $photo = PuzzleImageFingerprint::sql('pst.finished_puzzle_photo');
        $image = PuzzleImageFingerprint::sql('p.image');
        $current = "(pst.finished_puzzle_photo IS NOT NULL AND {$photo} = a.photo_fingerprint AND p.image IS NOT NULL AND {$image} = a.image_fingerprint AND pl.is_private = false)";
        $parameters = [
            'analyzer' => $analyzer->value,
            'limit' => $limit,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $conditions = '';

        if ($after !== null) {
            $conditions .= ' AND a.id > :after';
            $parameters['after'] = strtolower($after);
        }

        if ($onlyCurrent) {
            $conditions .= " AND {$current}";
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    a.id,
    pst.puzzle_id,
    a.analyzer_version,
    a.photo_fingerprint,
    a.image_fingerprint,
    a.result,
    a.analyzed_at,
    a.received_at,
    {$current} AS current
FROM finished_photo_analysis a
JOIN puzzle_solving_time pst ON pst.id = a.solving_time_id
JOIN player pl ON pl.id = pst.player_id
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE a.analyzer = :analyzer
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
    {$conditions}
ORDER BY a.id
LIMIT :limit
SQL,
            $parameters,
        )->fetchAllAssociative();

        $items = [];
        $lastId = null;

        foreach ($rows as $row) {
            $id = $row['id'];
            $puzzleId = $row['puzzle_id'];
            $version = $row['analyzer_version'];
            $photoFingerprint = $row['photo_fingerprint'];
            $imageFingerprint = $row['image_fingerprint'];
            $result = $row['result'];
            $receivedAt = $row['received_at'];
            assert(is_string($id) && is_string($puzzleId) && is_string($version) && is_string($photoFingerprint) && is_string($imageFingerprint) && is_string($result) && is_string($receivedAt));

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
            $lastId = $id;

            $items[] = new FinishedPhotoAnalysisResult(
                puzzleId: $puzzleId,
                analyzer: $analyzer,
                analyzerVersion: $version,
                photoFingerprint: $photoFingerprint,
                imageFingerprint: $imageFingerprint,
                result: $decoded,
                analyzedAt: is_string($row['analyzed_at']) ? new DateTimeImmutable($row['analyzed_at']) : null,
                receivedAt: new DateTimeImmutable($receivedAt),
                current: (bool) $row['current'],
            );
        }

        return [
            'items' => $items,
            'nextCursor' => count($rows) === $limit ? $lastId : null,
        ];
    }
}
