<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleTagFacet;
use SpeedPuzzling\Web\Value\PuzzleTagGroup;
use SpeedPuzzling\Web\Value\PuzzleTaxonomy;
use Symfony\Component\Yaml\Yaml;

/**
 * The app's part of the image taxonomy (docs/features/insights/tags.md): which keys a page shows and their labels -
 * every label in all 6 locales.
 */
final class PuzzleTaxonomyTest extends TestCase
{
    private const array LOCALES = ['cs', 'de', 'en', 'es', 'fr', 'ja'];

    public function testTheImageTraitsGroupHoldsWhatInsightsShowsFirst(): void
    {
        $keys = array_map(static fn ($definition): string => $definition->key, PuzzleTaxonomy::inGroup(PuzzleTagGroup::ImageTraits));

        foreach (['trait.large_uniform_areas', 'trait.smooth_gradients', 'trait.busy_detail', 'trait.collage_grid', 'trait.text_heavy', 'trait.dark', 'style.photo', 'style.watercolour'] as $expected) {
            self::assertContains($expected, $keys);
        }

        // The pipeline's 16 styles, each a photo or not
        self::assertCount(16, array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'style.')));
        self::assertTrue(PuzzleTaxonomy::isPhoto('style.photo_aerial'));
        self::assertFalse(PuzzleTaxonomy::isPhoto('style.cartoon_comic'));
        self::assertNull(PuzzleTaxonomy::isPhoto('trait.dark'));
        self::assertSame(PuzzleTaxonomy::PHOTO_LABEL, PuzzleTaxonomy::definition('style.photo_macro')?->labelKey);
        self::assertSame(PuzzleTagFacet::Trait, PuzzleTaxonomy::definition('trait.dark')?->facet);
        self::assertNull(PuzzleTaxonomy::definition('theme.pets.cats'), 'no label yet - not in the registry');
    }

    public function testEveryLabelExistsInEveryLocale(): void
    {
        $labelKeys = array_unique(array_map(static fn ($definition): string => $definition->labelKey, PuzzleTaxonomy::definitions()));

        foreach (self::LOCALES as $locale) {
            $messages = Yaml::parseFile(__DIR__ . '/../../translations/messages.' . $locale . '.yml');
            self::assertIsArray($messages);

            foreach ($labelKeys as $labelKey) {
                $value = $messages;

                foreach (explode('.', $labelKey) as $part) {
                    self::assertIsArray($value, $locale . ': ' . $labelKey);
                    self::assertArrayHasKey($part, $value, $locale . ': ' . $labelKey);
                    $value = $value[$part];
                }

                self::assertIsString($value, $locale . ': ' . $labelKey);
                self::assertNotSame('', trim($value));
            }
        }
    }

    public function testWellFormedKeys(): void
    {
        foreach (['trait.dark', 'theme.pets.cats', 'style.photo', 'licence.star_wars', 'image.box_front'] as $key) {
            self::assertTrue(PuzzleTaxonomy::isWellFormedKey($key), $key);
        }

        foreach (['dark', 'Trait.dark', 'trait.', 'unknown.key', 'theme.a.b.c', 'trait.dark-side', 'trait.' . str_repeat('a', 100)] as $key) {
            self::assertFalse(PuzzleTaxonomy::isWellFormedKey($key), $key);
        }
    }
}
