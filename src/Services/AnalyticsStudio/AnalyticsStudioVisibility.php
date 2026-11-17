<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use SpeedPuzzling\Web\Results\RoundPuzzlesNow;
use SpeedPuzzling\Web\Results\VisibleRoundPuzzle;

/**
 * What the analytics studio may know of a round's puzzles right now, as one short hash (analytics-studio.md §2.3, R1):
 * the visible slots only - round puzzle id, puzzle id and whether the picture is out - in slot order. A reveal (of a
 * name or only of a picture) changes it; a hidden puzzle never enters it, so no cache key, URL or answer depends on
 * which puzzle is hidden.
 */
final class AnalyticsStudioVisibility
{
    public static function hash(RoundPuzzlesNow $puzzles): string
    {
        $parts = [];

        foreach ($puzzles->slots as $slot) {
            if ($slot->puzzle === null) {
                continue;
            }

            $parts[] = strtolower($slot->roundPuzzleId) . ':' . strtolower($slot->puzzle->puzzleId) . ':' . ($slot->puzzle->imageHidden ? '0' : '1');
        }

        return substr(sha1(implode(',', $parts)), 0, 16);
    }

    /**
     * The visible puzzles in slot order, keyed by their slot (round puzzle id) - the only puzzles any studio statement
     * ever reads about.
     *
     * @return array<string, VisibleRoundPuzzle>
     */
    public static function visibleBySlot(RoundPuzzlesNow $puzzles): array
    {
        $visible = [];

        foreach ($puzzles->slots as $slot) {
            if ($slot->puzzle !== null) {
                $visible[strtolower($slot->roundPuzzleId)] = $slot->puzzle;
            }
        }

        return $visible;
    }

    /**
     * @return list<string> the visible puzzles' ids, each once, in slot order
     */
    public static function visiblePuzzleIds(RoundPuzzlesNow $puzzles): array
    {
        $ids = [];

        foreach (self::visibleBySlot($puzzles) as $puzzle) {
            $ids[strtolower($puzzle->puzzleId)] = true;
        }

        return array_keys($ids);
    }
}
