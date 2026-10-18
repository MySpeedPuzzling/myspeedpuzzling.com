<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ImageAnalyzer;

/**
 * A stored analysis of a puzzle's box image (PuzzleImageAnalysis) with its payload decoded. `current`: it describes
 * the picture the puzzle has now - a consumer showing analyses (Insights) uses only the current ones.
 */
readonly final class PuzzleImageAnalysisResult
{
    /**
     * @param array<string, mixed> $result
     */
    public function __construct(
        public string $puzzleId,
        public ImageAnalyzer $analyzer,
        public string $analyzerVersion,
        public string $imageFingerprint,
        public bool $current,
        public null|DateTimeImmutable $analyzedAt,
        public DateTimeImmutable $receivedAt,
        public array $result,
    ) {
    }

    /**
     * @param array<string, mixed> $row puzzle_id, analyzer, analyzer_version, image_fingerprint, current, analyzed_at,
     *                                  received_at, result (JSON) - null for an analyzer no longer in ImageAnalyzer
     */
    public static function fromDatabaseRow(array $row): null|self
    {
        assert(is_string($row['puzzle_id']));
        assert(is_string($row['analyzer']));
        assert(is_string($row['analyzer_version']));
        assert(is_string($row['image_fingerprint']));
        assert(is_string($row['received_at']));
        assert(is_string($row['result']));

        $analyzer = ImageAnalyzer::tryFrom($row['analyzer']);

        if ($analyzer === null) {
            return null;
        }

        $result = json_decode($row['result'], true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($result));
        /** @var array<string, mixed> $result */

        return new self(
            puzzleId: $row['puzzle_id'],
            analyzer: $analyzer,
            analyzerVersion: $row['analyzer_version'],
            imageFingerprint: $row['image_fingerprint'],
            current: $row['current'] === true,
            analyzedAt: is_string($row['analyzed_at']) ? new DateTimeImmutable($row['analyzed_at']) : null,
            receivedAt: new DateTimeImmutable($row['received_at']),
            result: $result,
        );
    }
}
