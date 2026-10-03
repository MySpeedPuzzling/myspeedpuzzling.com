<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The difficulty chips of live-component filters (_difficulty_filter_live.html.twig: profile results, marketplace).
 * Values are strings, as checkboxes send them: a DifficultyTier value, or "0" = not rated yet - the meaning of
 * PuzzleSearchCriteria::UNRATED_DIFFICULTY on the puzzle search.
 */
final class DifficultyFilter
{
    /**
     * Only known values, each once, ascending ("0" first) - whatever a request or the URL carried.
     *
     * @param array<mixed> $values
     * @return list<string>
     */
    public static function normalize(array $values): array
    {
        $tiers = [];

        foreach ($values as $value) {
            if (is_string($value) === false && is_int($value) === false) {
                continue;
            }

            $value = (string) $value;

            if (preg_match('/^\d$/', $value) !== 1) {
                continue;
            }

            if ((int) $value === PuzzleSearchCriteria::UNRATED_DIFFICULTY || DifficultyTier::tryFrom((int) $value) !== null) {
                $tiers[$value] = $value;
            }
        }

        sort($tiers);

        return $tiers;
    }

    /**
     * The values as tier numbers for SQL (0 = not rated yet).
     *
     * @param list<string> $values normalized
     * @return list<int>
     */
    public static function toInts(array $values): array
    {
        return array_map(intval(...), $values);
    }

    /**
     * Every chip: the tiers from the easiest, then "not rated yet".
     *
     * @return list<array{value: string, tier: null|DifficultyTier}>
     */
    public static function options(): array
    {
        $options = [];

        foreach ([...DifficultyTier::cases(), null] as $tier) {
            $options[] = [
                'value' => (string) ($tier->value ?? PuzzleSearchCriteria::UNRATED_DIFFICULTY),
                'tier' => $tier,
            ];
        }

        return $options;
    }
}
