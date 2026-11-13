<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use Random\Randomizer;

/**
 * How long a generated solve takes (docs/features/sandbox/README.md "Data"). Made up, but shaped like the real thing:
 * a base by piece count, the player's speed and the puzzle's difficulty - both derived from their ids, so a sandbox
 * player stays as fast and a puzzle as hard in the seed and in every later simulated result, with nothing stored -
 * pairs and teams faster, repeats faster, and some noise.
 */
final class SandboxTimeModel
{
    /**
     * The player's speed: < 1 faster than the base, > 1 slower - between 0.55 and 1.9, most around 1.
     */
    public static function playerSpeed(string $playerId): float
    {
        return 0.55 + 1.35 * self::unitFromId('speed' . $playerId) ** 1.6;
    }

    /**
     * The puzzle's difficulty: between 0.8 and 1.35.
     */
    public static function puzzleDifficulty(string $puzzleId): float
    {
        return 0.8 + 0.55 * self::unitFromId('difficulty' . $puzzleId);
    }

    /**
     * How much the player puzzles, relative to the others: a few heavy players (up to ~1), many occasional ones (~0.03).
     */
    public static function activity(string $playerId): float
    {
        return 0.03 + self::unitFromId('activity' . $playerId) ** 4;
    }

    /**
     * The piece count the player mostly solves: 500 or 1000 for most, some prefer smaller or bigger ones.
     */
    public static function preferredPieces(string $playerId): int
    {
        $unit = self::unitFromId('pieces' . $playerId);

        return match (true) {
            $unit < 0.12 => 300,
            $unit < 0.62 => 500,
            $unit < 0.94 => 1000,
            default => 1500,
        };
    }

    /**
     * Seconds of a typical solo first attempt: ~12 min for 100 pieces, ~71 min for 500, ~2.6 h for 1000.
     */
    public static function baseSeconds(int $piecesCount): float
    {
        return $piecesCount * 8.5 * ($piecesCount / 500) ** 0.12;
    }

    public static function seconds(
        Randomizer $randomizer,
        string $playerId,
        string $puzzleId,
        int $piecesCount,
        int $attempt,
        int $puzzlers,
    ): int {
        $group = match (true) {
            $puzzlers <= 1 => 1.0,
            $puzzlers === 2 => 0.62,
            default => 0.45,
        };
        $repeat = match (true) {
            $attempt <= 1 => 1.0,
            $attempt === 2 => 0.88,
            default => 0.82,
        };
        // Log-normal noise, sigma 0.12 (Box-Muller)
        $noise = exp(0.12 * sqrt(-2 * log(max($randomizer->nextFloat(), 1e-9))) * cos(2 * M_PI * $randomizer->nextFloat()));

        $seconds = self::baseSeconds($piecesCount)
            * self::playerSpeed($playerId)
            * self::puzzleDifficulty($puzzleId)
            * $group
            * $repeat
            * $noise;

        // Never faster than 1 piece per second per puzzler: the add-time handler refuses >= 100 pieces a minute anyway
        return max((int) round($seconds), (int) ceil($piecesCount / max(1, $puzzlers)));
    }

    /**
     * A stable number in [0, 1) from an id.
     */
    private static function unitFromId(string $value): float
    {
        return hexdec(substr(hash('sha256', $value), 0, 12)) / 0x1000000000000;
    }
}
