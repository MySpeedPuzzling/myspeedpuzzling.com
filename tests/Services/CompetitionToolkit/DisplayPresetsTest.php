<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayPresets;
use SpeedPuzzling\Web\Value\DisplayDesign;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;

/**
 * The 7 presets of D-displays-overlays.md §6: valid, unchanged by the sanitizer (no typo silently dropped, no value
 * clamped), idempotent, and the live ones are exactly the two that are meant to be.
 */
final class DisplayPresetsTest extends TestCase
{
    private const string COMPETITION = '018d0004-0000-0000-0000-00000000c0de';

    /**
     * @return list<array{DisplayPreset, DisplayKind}>
     */
    public static function presetsWithKinds(): array
    {
        $pairs = [];

        foreach (DisplayPreset::cases() as $preset) {
            foreach (DisplayKind::cases() as $kind) {
                if ($preset->fits($kind)) {
                    $pairs[] = [$preset, $kind];
                }
            }
        }

        return $pairs;
    }

    public function testEveryPresetFitsOneKindAndBlankFitsBoth(): void
    {
        self::assertCount(8, self::presetsWithKinds());
        self::assertTrue(DisplayPreset::Blank->fits(DisplayKind::Screen));
        self::assertTrue(DisplayPreset::Blank->fits(DisplayKind::Overlay));
        self::assertFalse(DisplayPreset::ObsCornerTimer->fits(DisplayKind::Screen));
        self::assertFalse(DisplayPreset::ClassicTimer->fits(DisplayKind::Overlay));
    }

    public function testAPresetOfTheOtherKindIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DisplayPresets::definition(DisplayPreset::ObsLowerThird, DisplayKind::Screen);
    }

    public function testEveryPresetNormalisesToItselfAndKeepsEveryValueItDefines(): void
    {
        $sanitizer = new DisplayDesignSanitizer();
        $presets = new DisplayPresets($sanitizer);

        foreach (self::presetsWithKinds() as [$preset, $kind]) {
            $label = $preset->value . ' / ' . $kind->value;
            $design = $presets->design($preset, $kind, self::COMPETITION);

            // sanitize(preset) == preset
            self::assertSame($design, $sanitizer->normaliseArray($design, self::COMPETITION), $label);

            // Nothing of the definition is lost or changed on the way (a typo in a key, a value out of range)
            $definition = DisplayPresets::definition($preset, $kind);
            self::assertEquals($design, array_replace_recursive($design, $definition), $label);

            self::assertSame($preset->showsLiveResults(), DisplayDesignSanitizer::usesLiveResults($design), $label);
            self::assertSame($kind === DisplayKind::Overlay ? 'transparent' : 'color', self::at($design, 'canvas', 'background', 'type'), $label);
            self::assertSame([], DisplayDesignSanitizer::imagePaths($design), $label);
        }
    }

    public function testBlankIsTheStoredBlankDesign(): void
    {
        $presets = new DisplayPresets(new DisplayDesignSanitizer());

        foreach (DisplayKind::cases() as $kind) {
            self::assertSame(DisplayDesign::blank($kind), $presets->design(DisplayPreset::Blank, $kind, self::COMPETITION));
        }
    }

    public function testTheTimerPresetsShowResultsOnlyWhereMeantTo(): void
    {
        $presets = new DisplayPresets(new DisplayDesignSanitizer());

        self::assertFalse(DisplayDesignSanitizer::usesResults($presets->design(DisplayPreset::ClassicTimer, DisplayKind::Screen, self::COMPETITION)));
        self::assertFalse(DisplayDesignSanitizer::usesResults($presets->design(DisplayPreset::TimerWithSponsorBar, DisplayKind::Screen, self::COMPETITION)));
        self::assertFalse(DisplayDesignSanitizer::usesResults($presets->design(DisplayPreset::ObsCornerTimer, DisplayKind::Overlay, self::COMPETITION)));
        self::assertFalse(DisplayDesignSanitizer::usesResults($presets->design(DisplayPreset::ObsLowerThird, DisplayKind::Overlay, self::COMPETITION)));
        self::assertTrue(DisplayDesignSanitizer::usesResults($presets->design(DisplayPreset::TimerWithLiveBoard, DisplayKind::Screen, self::COMPETITION)));
        self::assertTrue(DisplayDesignSanitizer::usesResults($presets->design(DisplayPreset::ObsLeaderboardSidebar, DisplayKind::Overlay, self::COMPETITION)));
    }

    /**
     * The value at a path of a decoded JSON answer (null when it is not there).
     */
    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}
