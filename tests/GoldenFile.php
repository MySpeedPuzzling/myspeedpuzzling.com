<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

/**
 * Byte-identity pins (competition toolkit baselines, R-review-and-plan.md §2.9): what a page or JSON answer of an
 * untouched competition is, kept in a file next to the test. A difference fails - an opt-in feature must leave an
 * event that does not use it exactly as it was.
 *
 * A deliberate change (named in its PR) rewrites the file: run the test with UPDATE_GOLDEN=1 and commit the diff.
 */
trait GoldenFile
{
    private static function assertMatchesGoldenFile(string $path, string $actual, string $what): void
    {
        if (getenv('UPDATE_GOLDEN') === '1') {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }

            file_put_contents($path, $actual);
        }

        self::assertFileExists($path, sprintf('No golden file for %s yet - run the test once with UPDATE_GOLDEN=1 and commit it.', $what));
        self::assertSame((string) file_get_contents($path), $actual, sprintf('%s is no longer byte-identical to its golden file %s. An untouched competition must not change; a deliberate change is rewritten with UPDATE_GOLDEN=1 and named in the PR.', $what, basename($path)));
    }
}
