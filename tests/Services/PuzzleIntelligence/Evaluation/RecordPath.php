<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Evaluation;

use PHPUnit\Framework\Assert;

/**
 * Reads a value out of the nested JSON-like arrays of a gate record in tests, failing the test on a missing step.
 */
final class RecordPath
{
    public static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $step) {
            Assert::assertIsArray($value, sprintf('No array at "%s"', $step));
            Assert::assertArrayHasKey($step, $value);
            $value = $value[$step];
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    public static function array(mixed $value, int|string ...$path): array
    {
        $found = self::at($value, ...$path);
        Assert::assertIsArray($found);

        return $found;
    }

    /**
     * A k bucket of a group's `skill` list, by its "k"
     *
     * @return array<mixed>
     */
    public static function skill(mixed $group, string $bucket): array
    {
        foreach (self::array($group, 'skill') as $entry) {
            if (is_array($entry) && ($entry['k'] ?? null) === $bucket) {
                return $entry;
            }
        }

        Assert::fail(sprintf('No skill bucket "%s"', $bucket));
    }
}
