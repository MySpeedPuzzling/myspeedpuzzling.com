<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PuzzleDifficultyLabel;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * Training labels for the image pipeline's difficulty model (docs/features/internal-api.md, "Puzzle difficulty labels
 * and estimates", implementation-plan.md §5.1): every puzzle with at least one as-of difficulty index, from what the
 * insights batch stores in puzzle_difficulty (sample_size = k, sum_log_indices, the median as difficulty_score from
 * 5 indices) - aggregates only, no player's data leaves through here. A row the batch has not yet computed with as-of
 * indices (sum_log_indices NULL) is no label. Secret puzzles (hide_until / hide_image_until ahead) are never listed:
 * no training row for them.
 */
readonly final class GetPuzzleDifficultyLabels
{
    public const int DEFAULT_PAGE_LIMIT = 1000;

    // A row is ~250 bytes of JSON - 5000 is ~1.3 MB, the whole catalogue in a handful of pages
    public const int MAX_PAGE_LIMIT = 5000;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A page in puzzle id order (keyset: `after` = the last puzzle id of the previous page; walks puzzle_difficulty's
     * primary key). `nextCursor` is null on the last page.
     *
     * @return array{items: list<PuzzleDifficultyLabel>, nextCursor: null|string}
     */
    public function page(int $limit, null|string $after): array
    {
        $limit = max(1, min($limit, self::MAX_PAGE_LIMIT));
        $fingerprint = PuzzleImageFingerprint::sql('p.image');
        $parameters = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ];
        $afterCondition = '';

        if ($after !== null) {
            $afterCondition = 'AND pd.puzzle_id > :after';
            $parameters['after'] = strtolower($after);
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    pd.puzzle_id,
    pd.sample_size,
    pd.sum_log_indices,
    pd.difficulty_score,
    p.pieces_count,
    p.manufacturer_id,
    p.added_at,
    {$fingerprint} AS image_fingerprint
FROM puzzle_difficulty pd
JOIN puzzle p ON p.id = pd.puzzle_id
WHERE pd.sample_size >= 1
    AND pd.sum_log_indices IS NOT NULL
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
    {$afterCondition}
ORDER BY pd.puzzle_id
LIMIT :limit
SQL,
            $parameters,
        )->fetchAllAssociative();

        $items = array_map(PuzzleDifficultyLabel::fromDatabaseRow(...), $rows);

        return [
            'items' => $items,
            'nextCursor' => count($items) === $limit ? $items[$limit - 1]->puzzleId : null,
        ];
    }
}
