<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\RequiredImageAnalyses;

/**
 * The `requires` filter of the image analysis work list and claims (docs/features/internal-api.md, "Puzzle image
 * analysis" - requires).
 */
final class RequiredImageAnalysesTest extends TestCase
{
    public function testParsesKnownAnalyzersOnceEach(): void
    {
        $requires = RequiredImageAnalyses::fromValues(['artwork', 'ocr', 'artwork'], ImageAnalyzer::Describe);

        self::assertInstanceOf(RequiredImageAnalyses::class, $requires);
        self::assertSame([ImageAnalyzer::Artwork, ImageAnalyzer::Ocr], $requires->analyzers);
        self::assertSame(['artwork', 'ocr'], $requires->values());
        self::assertSame(['required_0' => 'artwork', 'required_1' => 'ocr'], $requires->parameters());

        $condition = $requires->sqlCondition('p');
        self::assertSame(2, substr_count($condition, 'EXISTS ('));
        self::assertStringContainsString('required_1.analyzer = :required_1 AND required_1.puzzle_id = p.id', $condition);
        self::assertStringContainsString('required_0.image_fingerprint = left(md5(p.image), 16)', $condition);
    }

    public function testNothingRequiredIsNoFilter(): void
    {
        $requires = RequiredImageAnalyses::fromValues([], ImageAnalyzer::Colors);

        self::assertInstanceOf(RequiredImageAnalyses::class, $requires);
        self::assertTrue($requires->isEmpty());
        self::assertSame('', $requires->sqlCondition('p'));
        self::assertSame([], $requires->parameters());
        self::assertTrue(RequiredImageAnalyses::none()->isEmpty());
    }

    public function testRefusesAnUnknownAnalyzerAndTheAnalyzerItself(): void
    {
        $unknown = RequiredImageAnalyses::fromValues(['artwork', 'crop'], ImageAnalyzer::Describe);
        self::assertIsString($unknown);
        self::assertStringContainsString('crop', $unknown);

        $itself = RequiredImageAnalyses::fromValues(['artwork', 'describe'], ImageAnalyzer::Describe);
        self::assertIsString($itself);
        self::assertStringContainsString('describe', $itself);

        // Without an analyzer (the plain list) any known analyzer may be required
        self::assertInstanceOf(RequiredImageAnalyses::class, RequiredImageAnalyses::fromValues(['describe'], null));
    }
}
