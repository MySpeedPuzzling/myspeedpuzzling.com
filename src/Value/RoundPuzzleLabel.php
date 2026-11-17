<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The neutral name of a round puzzle that judges and competitors see before the reveal (competition toolkit A2): the
 * organiser's own label ("Blue box"), or a letter by its place in the round - A, B, C … Z, then 27, 28 …
 *
 * The place is the organiser's order (competition_round_puzzle.position), then the order the puzzles were added in
 * (the row id): ORDER BY position NULLS LAST, id - never the puzzle's name, which would hint at a secret one. Rows saved
 * before positions existed have none and keep their add order, so nothing is backfilled (sort() / SQL_ORDER).
 */
final class RoundPuzzleLabel
{
    public const int MAX_LENGTH = 40;

    /**
     * The order of a round's puzzles in SQL - for an alias of competition_round_puzzle
     */
    public static function sqlOrder(string $alias): string
    {
        return sprintf('%1$s.position NULLS LAST, %1$s.id', $alias);
    }

    public static function default(int $position): string
    {
        return $position >= 1 && $position <= 26 ? chr(64 + $position) : (string) $position;
    }

    public static function effective(null|string $label, int $position): string
    {
        return $label ?? self::default($position);
    }

    /**
     * Trimmed, empty = none (the letter); refused when longer than MAX_LENGTH
     */
    public static function normalise(null|string $label): null|string
    {
        if ($label === null) {
            return null;
        }

        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');

        if ($label === '') {
            return null;
        }

        if (mb_strlen($label) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A puzzle label is at most %d characters.', self::MAX_LENGTH));
        }

        return $label;
    }

    /**
     * Sorts a round's puzzles the way SQL does (sqlOrder()): by position, rows without one last, then by id. Ids are
     * compared as lower-case strings, which is the byte order PostgreSQL compares uuids in.
     *
     * @template T
     * @param list<T> $items
     * @param callable(T): array{null|int, string} $positionAndId
     * @return list<T>
     */
    public static function sort(array $items, callable $positionAndId): array
    {
        usort($items, static function (mixed $a, mixed $b) use ($positionAndId): int {
            [$positionA, $idA] = $positionAndId($a);
            [$positionB, $idB] = $positionAndId($b);

            return [$positionA === null ? 1 : 0, $positionA ?? 0, strtolower($idA)]
                <=> [$positionB === null ? 1 : 0, $positionB ?? 0, strtolower($idB)];
        });

        return $items;
    }
}
