<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PartnerFinder;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Exceptions\PartnerListingInvalid;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerStrengthCatalogue;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerStrengthNormalizer;
use SpeedPuzzling\Web\Value\PartnerStrength;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The catalogue loads the translations of all 6 locales (~23 MB without opcache) - so the services are fetched, never
 * kept in a property (PHPUnit keeps every test object to the end of the run), and each finished kernel is collected
 * right away instead of piling up as cyclic garbage (479 MB for this class without it).
 */
final class PartnerStrengthNormalizerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
    }

    public function testPresetsKeepTheirOrderOnce(): void
    {
        $normalized = $this->normalizer()->normalize(['sky', 'edges', 'sky'], []);

        self::assertSame([PartnerStrength::Sky, PartnerStrength::Edges], $normalized->presets);
        self::assertSame(['sky', 'edges'], $normalized->presetValues());
        self::assertSame([], $normalized->custom);
    }

    public function testCustomChipsAreTrimmedCollapsedAndDeduplicatedByFold(): void
    {
        $normalized = $this->normalizer()->normalize([], ['  Dark   puzzles ', '', '   ', 'DARK PUZZLES', 'Černé puzzle', 'cerne PUZZLE', "Night\tsky"]);

        self::assertSame([], $normalized->presets);
        self::assertSame(['Dark puzzles', 'Černé puzzle', 'Night sky'], $normalized->custom);
    }

    /**
     * @return iterable<string, array{string, PartnerStrength}>
     */
    public static function presetSpellings(): iterable
    {
        yield 'English label' => ['Edges & frame', PartnerStrength::Edges];
        yield 'English label, other case and spacing' => ['  sky  &  GRADIENTS ', PartnerStrength::Sky];
        yield 'enum value' => ['colour_blocks', PartnerStrength::ColourBlocks];
        yield 'enum value with a space' => ['piece shapes', PartnerStrength::PieceShapes];
        yield 'Czech label' => ['Třídění', PartnerStrength::Sorting];
        yield 'Czech label without accents' => ['tvary dilku', PartnerStrength::PieceShapes];
        yield 'Czech label 2' => ['Klidná hlava', PartnerStrength::StayingCalm];
        yield 'German label' => ['Ränder & Rahmen', PartnerStrength::Edges];
        yield 'German label folded' => ['randER & rahmen', PartnerStrength::Edges];
        yield 'German label 2' => ['Arbeit aufteilen', PartnerStrength::SplittingWork];
        yield 'Spanish label' => ['Caras y personas', PartnerStrength::Faces];
        yield 'French label' => ['Garder son calme', PartnerStrength::StayingCalm];
        yield 'Japanese label' => ['ピースの形', PartnerStrength::PieceShapes];
    }

    #[DataProvider('presetSpellings')]
    public function testACustomChipReadingLikeAPresetBecomesThePreset(string $typed, PartnerStrength $expected): void
    {
        $normalized = $this->normalizer()->normalize([], [$typed]);

        self::assertSame([$expected], $normalized->presets);
        self::assertSame([], $normalized->custom);
    }

    public function testACustomChipOfAPickedPresetIsNotAddedTwice(): void
    {
        $normalized = $this->normalizer()->normalize(['edges', 'sky'], ['Okraje a rám', 'Dark puzzles', 'Sorting']);

        self::assertSame([PartnerStrength::Edges, PartnerStrength::Sky, PartnerStrength::Sorting], $normalized->presets);
        self::assertSame(['Dark puzzles'], $normalized->custom);
    }

    public function testFourPresetsAndFiveCustomChipsAreTheMost(): void
    {
        $normalized = $this->normalizer()->normalize(['sorting', 'edges', 'sky', 'faces'], ['a1', 'a2', 'a3', 'a4', 'a5', 'A5']);

        self::assertCount(4, $normalized->presets);
        self::assertSame(['a1', 'a2', 'a3', 'a4', 'a5'], $normalized->custom);
    }

    public function testThirtyCharactersAreTheMost(): void
    {
        $thirty = str_repeat('ž', 30);

        self::assertSame([$thirty], $this->normalizer()->normalize([], [$thirty])->custom);

        $this->assertInvalid([], [$thirty . 'a'], 'partner_finder.invalid.custom_strength_too_long', PartnerListingInvalid::FIELD_CUSTOM_STRENGTHS);
    }

    public function testAFifthPresetIsRefusedNeverCut(): void
    {
        $this->assertInvalid(['sorting', 'edges', 'sky', 'faces'], ['Text & letters'], 'partner_finder.invalid.too_many_strengths', PartnerListingInvalid::FIELD_STRENGTHS);
    }

    public function testASixthCustomChipIsRefusedNeverCut(): void
    {
        $this->assertInvalid([], ['a1', 'a2', 'a3', 'a4', 'a5', 'a6'], 'partner_finder.invalid.too_many_custom_strengths', PartnerListingInvalid::FIELD_CUSTOM_STRENGTHS);
    }

    public function testAnUnknownPresetIsRefused(): void
    {
        $this->assertInvalid(['speed'], [], 'partner_finder.invalid.strength_unknown', PartnerListingInvalid::FIELD_STRENGTHS);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedCharacters(): iterable
    {
        yield 'markup' => ['<b>bold</b>'];
        yield 'greater than' => ['fast > slow'];
        yield 'control character' => ["bell\u{0007}"];
        yield 'zero-width space' => ["zero\u{200B}width"];
    }

    #[DataProvider('refusedCharacters')]
    public function testMarkupAndControlCharactersAreRefused(string $chip): void
    {
        $this->assertInvalid([], [$chip], 'partner_finder.invalid.custom_strength_characters', PartnerListingInvalid::FIELD_CUSTOM_STRENGTHS);
    }

    public function testTheCatalogueKnowsEveryPresetInEveryLocale(): void
    {
        $catalogue = self::getContainer()->get(PartnerStrengthCatalogue::class);
        $translator = self::getContainer()->get('translator');

        foreach (PartnerStrengthCatalogue::LOCALES as $locale) {
            foreach (PartnerStrength::cases() as $strength) {
                $label = $translator->trans($strength->translationKey(), domain: 'messages', locale: $locale);

                self::assertNotSame($strength->translationKey(), $label, $locale . ' misses ' . $strength->translationKey());
                self::assertSame($strength, $catalogue->find($label), $locale . ': ' . $label);
            }
        }
    }

    private function normalizer(): PartnerStrengthNormalizer
    {
        return self::getContainer()->get(PartnerStrengthNormalizer::class);
    }

    /**
     * @param list<string> $presets
     * @param list<string> $custom
     */
    private function assertInvalid(array $presets, array $custom, string $translationKey, string $field): void
    {
        try {
            $this->normalizer()->normalize($presets, $custom);
        } catch (PartnerListingInvalid $exception) {
            self::assertSame($translationKey, $exception->translationKey);
            self::assertSame($field, $exception->field);

            return;
        }

        self::fail('Expected PartnerListingInvalid ' . $translationKey);
    }
}
