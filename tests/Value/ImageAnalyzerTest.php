<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ImageAnalyzer;

/**
 * The analyzers the image pipeline pushes (docs/features/insights/image-taxonomy.md §11) - their keys are the API's and
 * the stored `analyzer` column's values, so a renamed key would orphan stored rows. Also listed in the OpenAPI spec.
 */
final class ImageAnalyzerTest extends TestCase
{
    public function testTheKeysThePipelineSends(): void
    {
        self::assertSame([
            'artwork',
            'colors',
            'traits',
            'ocr',
            'barcode',
            'vision_labels',
            'embed_siglip2',
            'embed_siglip2_full',
            'embed_dinov2',
            'embed_dinov2_patches',
            'segmentation',
            'describe',
            'piece_scale',
            'box_text',
            'zeroshot',
            'neighbours',
            'tags',
            'crop_trust',
        ], ImageAnalyzer::values());
    }

    public function testEveryKeyFitsItsColumnAndTheOpenApiSpecListsIt(): void
    {
        $spec = (string) file_get_contents(__DIR__ . '/../../docs/features/internal-api.openapi.yaml');
        self::assertSame(1, preg_match('/ImageAnalyzer:\n\s+type: string\n\s+enum: \[([^\]]+)\]/', $spec, $matches));

        self::assertSame(ImageAnalyzer::values(), array_map(trim(...), explode(',', $matches[1])));

        foreach (ImageAnalyzer::cases() as $analyzer) {
            // puzzle_image_analysis.analyzer is VARCHAR(32)
            self::assertLessThanOrEqual(32, strlen($analyzer->value));
            // The largest expected payload (ocr, 400 lines) is ~16 KB, an embedding ~3 KB per vector
            self::assertGreaterThanOrEqual(32768, $analyzer->maxResultBytes());
        }
    }
}
