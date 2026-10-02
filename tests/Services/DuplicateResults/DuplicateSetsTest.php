<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DuplicateResults;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateSets;

/**
 * Cases that share a result are one set (docs/features/duplicate-results.md, "Review page").
 */
final class DuplicateSetsTest extends TestCase
{
    public function testAResultSavedThreeTimesIsOneSet(): void
    {
        $sets = DuplicateSets::group([
            'ab' => ['a', 'b'],
            'xy' => ['x', 'y'],
            'ac' => ['a', 'c'],
            'bc' => ['b', 'c'],
        ]);

        self::assertSame([['ab', 'ac', 'bc'], ['xy']], $sets);
    }

    public function testCopiesLinkedOnlyThroughAnotherCopyBelongToTheSameSet(): void
    {
        // a-b and c-d are joined by b-c, which comes last
        $sets = DuplicateSets::group([['a', 'b'], ['c', 'd'], ['e', 'f'], ['B', 'c']]);

        self::assertSame([[0, 1, 3], [2]], $sets);
    }

    public function testNoCasesNoSets(): void
    {
        self::assertSame([], DuplicateSets::group([]));
    }

    public function testEveryResultOnceInLowerCase(): void
    {
        self::assertSame(['a', 'b', 'c'], DuplicateSets::timeIdsOf([['A', 'b'], ['a', 'c']]));
    }
}
