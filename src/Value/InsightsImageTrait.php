<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The image traits Insights filters and splits by: the PuzzleTagGroup::ImageTraits keys of the registry
 * (PuzzleTaxonomy), the photo styles folded into "photo" and the drawn styles into "drawn". Labels: PuzzleTaxonomy's
 * label keys (labelKey()), all 6 locales.
 */
enum InsightsImageTrait: string
{
    case LargeUniformAreas = 'large_uniform_areas';
    case SmoothGradients = 'smooth_gradients';
    case BusyDetail = 'busy_detail';
    case CollageGrid = 'collage_grid';
    case TextHeavy = 'text_heavy';
    case Dark = 'dark';
    case Photo = 'photo';
    case Drawn = 'drawn';

    /**
     * The traits of a picture from its stored tag keys (puzzle_tag.tag_key), each once, in case order.
     *
     * @param list<string> $tagKeys
     * @return list<self>
     */
    public static function fromTagKeys(array $tagKeys): array
    {
        $traits = [];

        foreach ($tagKeys as $key) {
            $trait = self::ofTagKey($key);

            if ($trait !== null) {
                $traits[$trait->value] = $trait;
            }
        }

        $ordered = [];

        foreach (self::cases() as $case) {
            if (isset($traits[$case->value])) {
                $ordered[] = $case;
            }
        }

        return $ordered;
    }

    /**
     * The trait a registry key stands for. Memoized per key: the registry is constant data, and a page asks for every
     * tag of up to thousands of results.
     */
    public static function ofTagKey(string $key): null|self
    {
        /** @var array<string, null|self> $known */
        static $known = [];

        if (array_key_exists($key, $known) === false) {
            $known[$key] = match (true) {
                PuzzleTaxonomy::definition($key) === null => null,
                str_starts_with($key, 'trait.') => self::tryFrom(substr($key, strlen('trait.'))),
                PuzzleTaxonomy::isPhoto($key) === true => self::Photo,
                PuzzleTaxonomy::isPhoto($key) === false => self::Drawn,
                default => null,
            };
        }

        return $known[$key];
    }

    /**
     * Every tag key of the image-traits group - what the solo results statement reads from puzzle_tag.
     *
     * @return list<string>
     */
    public static function tagKeys(): array
    {
        return array_map(
            static fn (PuzzleTagDefinition $definition): string => $definition->key,
            PuzzleTaxonomy::inGroup(PuzzleTagGroup::ImageTraits),
        );
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Photo => PuzzleTaxonomy::PHOTO_LABEL,
            self::Drawn => PuzzleTaxonomy::DRAWN_LABEL,
            default => 'puzzle_tags.trait.' . $this->value,
        };
    }
}
