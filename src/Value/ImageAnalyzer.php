<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What an analysis of a puzzle's box image is about (docs/features/internal-api.md, "Puzzle image analysis") - one
 * current PuzzleImageAnalysis per puzzle and analyzer. The analyses are made outside the app (a pipeline on local
 * models) and pushed through the internal API; the app stores each result as it came, versioned by the analyzer.
 * A new kind of analysis (ocr, barcode, caption, …) is a new case here - nothing else changes; give it its own
 * maxResultBytes() when its payloads are bigger.
 *
 * What part of the image an analyzer looks at (the cropped artwork, the whole photo) is its own business - the
 * imageFingerprint always names the source image the analysis was made from.
 */
enum ImageAnalyzer: string
{
    // Where the artwork is in the image (many images are photos of the box): `bbox` {x, y, w, h} and/or `corners`
    // (4 points {x, y}), all fractions 0..1 of the image - PuzzleImageAnalysisInput checks them
    case Artwork = 'artwork';
    // Colour features of the artwork (palette, dominant colours, ...)
    case Colors = 'colors';
    // Structure features of the artwork (edges, detail, contrast, ...)
    case Traits = 'traits';
    // Theme / style tags
    case Tags = 'tags';

    // The default size limit of a result, JSON-encoded
    public const int DEFAULT_MAX_RESULT_BYTES = 65536;

    /**
     * How big a result of this analyzer may be, JSON-encoded (PuzzleImageAnalysisInput) - per analyzer, so one with
     * bigger payloads can get more without raising it for the others.
     */
    public function maxResultBytes(): int
    {
        // An analyzer with bigger payloads gets its own value here (match ($this) { self::Ocr => …, default => … })
        return self::DEFAULT_MAX_RESULT_BYTES;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $analyzer): string => $analyzer->value, self::cases());
    }
}
