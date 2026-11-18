<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\RoundDivision;

/**
 * The one rule by which a typed or imported value names a division (shared-puzzles-and-divisions.md §3.5): its id, its
 * name or its short label - folded like search text (SearchText::fold(): trimmed, inner whitespace collapsed, lower
 * case, accents folded - "  seniór   55+ " is "Senior 55+"). Only the divisions a round uses can be given to its
 * entries; a value naming another division of the event is `not_in_round`, one naming none `unknown_division`.
 * Used by SetRoundEntryDivisionsHandler (the internal API), the participants sheet (SheetPlanRun) and the participant
 * import (PlanBuilder), so the three never disagree. The browser's twin: matchDivision() in
 * assets/participants_sheet/sheet_divisions.js.
 */
final readonly class DivisionMatch
{
    public const string NOT_IN_ROUND = 'not_in_round';
    public const string UNKNOWN_DIVISION = 'unknown_division';

    /**
     * No value at all - "no division" in the sheet and the internal API, "leave as it is" in an import.
     */
    public static function isBlank(null|string $value): bool
    {
        return $value === null || self::key($value) === '';
    }

    public static function key(string $value): string
    {
        return SearchText::fold($value);
    }

    public static function matches(string $asked, string $id, string $name, null|string $shortName): bool
    {
        $wanted = self::key($asked);

        if ($wanted === '') {
            return false;
        }

        return strtolower($id) === $wanted
            || self::key($name) === $wanted
            || ($shortName !== null && self::key($shortName) === $wanted);
    }

    /**
     * The first of `$candidates` (in their order - the event's order) the value names.
     *
     * @template T
     * @param iterable<T> $candidates
     * @param callable(T): array{string, string, null|string} $describe id, name, short label
     * @return null|T
     */
    public static function first(string $asked, iterable $candidates, callable $describe): mixed
    {
        foreach ($candidates as $candidate) {
            [$id, $name, $shortName] = $describe($candidate);

            if (self::matches($asked, $id, $name, $shortName)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The division of the round the value names, else why not: NOT_IN_ROUND (a division of the event the round does not
     * use) or UNKNOWN_DIVISION.
     *
     * @param list<RoundDivision> $usable the round's divisions
     * @param list<RoundDivision> $ofEvent the event's divisions
     */
    public static function resolve(string $asked, array $usable, array $ofEvent): RoundDivision|string
    {
        $describe = static fn (RoundDivision $division): array => [$division->id, $division->name, $division->shortName];

        return self::first($asked, $usable, $describe)
            ?? (self::first($asked, $ofEvent, $describe) !== null ? self::NOT_IN_ROUND : self::UNKNOWN_DIVISION);
    }

    /**
     * The event's division the value names, null when none.
     *
     * @param list<RoundDivision> $ofEvent
     */
    public static function ofEvent(string $asked, array $ofEvent): null|RoundDivision
    {
        return self::first($asked, $ofEvent, static fn (RoundDivision $division): array => [$division->id, $division->name, $division->shortName]);
    }
}
