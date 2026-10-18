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
 * imageFingerprint always names the source image the analysis was made from. The list and the payload schemas:
 * docs/features/insights/image-taxonomy.md §11 (the largest expected payload, `ocr` capped at 400 lines, is ~16 KB, an
 * embedding ~3 KB per vector - all within the default limit).
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
    // Text on the box (full image): lines with boxes, languages
    case Ocr = 'ocr';
    // Barcodes read from the image (full image)
    case Barcode = 'barcode';
    // Platform vision labels, face/human counts and saliency of the artwork - no face crops or embeddings
    case VisionLabels = 'vision_labels';
    // SigLIP 2 image embeddings (crop + full), base64 float16
    case EmbedSiglip2 = 'embed_siglip2';
    // DINOv2 image embedding (crop), base64 float16
    case EmbedDinov2 = 'embed_dinov2';
    // Class shares, instance counts and a coarse class grid of the artwork
    case Segmentation = 'segmentation';
    // A vision-language model's caption and structured description of the artwork
    case Describe = 'describe';

    // Derived (computed from the raw analyses above, not from the image). `neighbours` (and anything else naming other
    // puzzles) may name a puzzle that became secret after it was computed - a consumer re-checks every referenced id
    // against hide_until / hide_image_until before showing it:
    // Facts parsed from `ocr` + `barcode` (pieces, EANs, product codes, brand mentions, ...)
    case BoxText = 'box_text';
    // Taxonomy key scores from the embeddings
    case Zeroshot = 'zeroshot';
    // The most similar puzzles by embedding
    case Neighbours = 'neighbours';
    // Theme / style tags - the fusion of the analyses
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
