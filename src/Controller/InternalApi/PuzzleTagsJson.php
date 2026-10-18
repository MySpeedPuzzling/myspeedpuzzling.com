<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeInterface;
use SpeedPuzzling\Web\Query\GetPuzzleTags;
use SpeedPuzzling\Web\Results\PuzzleTagCorrectionResult;
use SpeedPuzzling\Web\Results\PuzzleTagResult;
use SpeedPuzzling\Web\Value\PuzzleTaxonomy;

/**
 * A puzzle's tags and corrections as the internal API answers them (docs/features/internal-api.md, "Puzzle tags").
 */
final class PuzzleTagsJson
{
    /**
     * @return array<string, mixed>
     */
    public static function ofPuzzle(GetPuzzleTags $getPuzzleTags, string $puzzleId): array
    {
        $corrections = $getPuzzleTags->correctionsOf($puzzleId);

        return [
            'puzzleId' => strtolower($puzzleId),
            'taxonomyVersion' => PuzzleTaxonomy::VERSION,
            'tags' => array_map(self::tag(...), $getPuzzleTags->ofPuzzle($puzzleId)),
            'corrections' => array_map(self::correction(...), $corrections),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tag(PuzzleTagResult $tag): array
    {
        return [
            'key' => $tag->tagKey,
            'score' => $tag->score,
            'uncertain' => $tag->uncertain,
            'source' => $tag->source->value,
            'analysisVersion' => $tag->analysisVersion,
            'taxonomyVersion' => $tag->taxonomyVersion,
            // The registry's label key - null for a key no page shows yet
            'labelKey' => PuzzleTaxonomy::definition($tag->tagKey)?->labelKey,
            'updatedAt' => $tag->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function correction(PuzzleTagCorrectionResult $correction): array
    {
        return [
            'key' => $correction->tagKey,
            'verdict' => $correction->verdict->value,
            'note' => $correction->note,
            'decidedById' => $correction->decidedById,
            'decidedAt' => $correction->decidedAt->format(DateTimeInterface::ATOM),
            'taxonomyVersion' => $correction->taxonomyVersion,
        ];
    }
}
