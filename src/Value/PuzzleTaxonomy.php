<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The image taxonomy as far as the app needs to know it (docs/features/insights/tags.md; the full taxonomy with ~310
 * keys: docs/features/insights/image-taxonomy.md Appendix A, the pipeline's copy tools/puzzle-vision/puzzle_vision/
 * derive/taxonomy.py). Every key the `tags` analysis assigns is stored (puzzle_tag), known here or not; this registry
 * names the keys a page shows and their labels. Keys are never renamed - a retired key stays, deprecated.
 *
 * Only the image traits have labels so far - what Insights shows first. Another group = a PuzzleTagGroup case, its
 * definitions below and their labels in all 6 locales.
 */
final class PuzzleTaxonomy
{
    // The taxonomy version the registry follows - the pipeline's TAXONOMY_VERSION, also in every `tags` payload
    public const string VERSION = '1.0.0';

    public const int MAX_KEY_LENGTH = 100;

    // `<facet>.<key>` or `theme.<parent>.<child>`: lower case, digits and underscores
    private const string KEY_PATTERN = '/^[a-z]+(\.[a-z0-9_]+){1,2}$/';

    // Styles that are photographs - every other style is drawn or painted (photo vs illustration)
    private const array PHOTO_STYLES = ['photo', 'photo_aerial', 'photo_macro', 'photomosaic'];

    private const array DRAWN_STYLES = [
        'painting_oil_acrylic',
        'watercolour',
        'pencil_ink_drawing',
        'illustration_digital',
        'cartoon_comic',
        'vector_flat',
        'render_3d',
        'vintage_retro_poster',
        'collage_mixed',
        'pattern_textile',
        'map_cartographic',
        'pixel_art',
    ];

    // trait key => label key: large single-colour areas, gradients, busy / collage, text, dark
    private const array IMAGE_TRAITS = [
        'large_uniform_areas' => 'puzzle_tags.trait.large_uniform_areas',
        'smooth_gradients' => 'puzzle_tags.trait.smooth_gradients',
        'busy_detail' => 'puzzle_tags.trait.busy_detail',
        'collage_grid' => 'puzzle_tags.trait.collage_grid',
        'text_heavy' => 'puzzle_tags.trait.text_heavy',
        'dark' => 'puzzle_tags.trait.dark',
    ];

    public const string PHOTO_LABEL = 'puzzle_tags.look.photo';

    public const string DRAWN_LABEL = 'puzzle_tags.look.drawn';

    /**
     * @return array<string, PuzzleTagDefinition> keyed by the tag key
     */
    public static function definitions(): array
    {
        $definitions = [];

        foreach (self::IMAGE_TRAITS as $trait => $labelKey) {
            $definitions['trait.' . $trait] = new PuzzleTagDefinition('trait.' . $trait, PuzzleTagFacet::Trait, PuzzleTagGroup::ImageTraits, $labelKey);
        }

        foreach (self::PHOTO_STYLES as $style) {
            $definitions['style.' . $style] = new PuzzleTagDefinition('style.' . $style, PuzzleTagFacet::Style, PuzzleTagGroup::ImageTraits, self::PHOTO_LABEL);
        }

        foreach (self::DRAWN_STYLES as $style) {
            $definitions['style.' . $style] = new PuzzleTagDefinition('style.' . $style, PuzzleTagFacet::Style, PuzzleTagGroup::ImageTraits, self::DRAWN_LABEL);
        }

        return $definitions;
    }

    public static function definition(string $key): null|PuzzleTagDefinition
    {
        return self::definitions()[$key] ?? null;
    }

    /**
     * @return list<PuzzleTagDefinition>
     */
    public static function inGroup(PuzzleTagGroup $group): array
    {
        $inGroup = [];

        foreach (self::definitions() as $definition) {
            $inGroup[$definition->group->value][] = $definition;
        }

        return $inGroup[$group->value] ?? [];
    }

    /**
     * Whether a photograph (true), drawn or painted (false), or the key says nothing about it (null).
     */
    public static function isPhoto(string $key): null|bool
    {
        if (str_starts_with($key, 'style.') === false) {
            return null;
        }

        $style = substr($key, strlen('style.'));

        return match (true) {
            in_array($style, self::PHOTO_STYLES, true) => true,
            in_array($style, self::DRAWN_STYLES, true) => false,
            default => null,
        };
    }

    /**
     * A key the taxonomy could hold: `<facet>.<key>` of a known facet, at most MAX_KEY_LENGTH characters. Says nothing
     * about whether the key is in the registry - most keys are not (yet).
     */
    public static function isWellFormedKey(string $key): bool
    {
        return strlen($key) <= self::MAX_KEY_LENGTH
            && preg_match(self::KEY_PATTERN, $key) === 1
            && self::facetOf($key) !== null;
    }

    public static function facetOf(string $key): null|PuzzleTagFacet
    {
        return PuzzleTagFacet::tryFrom(explode('.', $key, 2)[0]);
    }

    /**
     * The same check in SQL (the writer filters the analysis' keys with it), for a column or an expression.
     */
    public static function sqlIsWellFormedKey(string $expression): string
    {
        $facets = implode('|', array_map(static fn (PuzzleTagFacet $facet): string => $facet->value, PuzzleTagFacet::cases()));

        return sprintf("(length(%1\$s) <= %2\$d AND %1\$s ~ '^(%3\$s)(\\.[a-z0-9_]+){1,2}$')", $expression, self::MAX_KEY_LENGTH, $facets);
    }
}
