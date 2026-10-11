<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\TimesBoardGuestCache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TimesBoardGuestCacheTest extends TestCase
{
    public function testAGuestsRowsAreReadOnceAndSharedByKey(): void
    {
        $cache = new TimesBoardGuestCache(new ArrayAdapter());
        /** @var ArrayObject<int, true> $reads */
        $reads = new ArrayObject();
        $fetch = static function () use ($reads): array {
            $reads->append(true);

            return [['position' => 1]];
        };

        self::assertSame([['position' => 1]], $cache->rows(true, 'solo|500||10|0|0', $fetch));
        self::assertSame([['position' => 1]], $cache->rows(true, 'solo|500||10|0|0', $fetch));
        self::assertCount(1, $reads, 'the second guest gets the cached rows');

        $cache->rows(true, 'solo|500|cz|10|0|0', $fetch);
        self::assertCount(2, $reads, 'another board (here a country) is its own entry');
    }

    public function testASignedInViewerAlwaysReadsTheDatabase(): void
    {
        $cache = new TimesBoardGuestCache(new ArrayAdapter());
        /** @var ArrayObject<int, true> $reads */
        $reads = new ArrayObject();
        $fetch = static function () use ($reads): array {
            $reads->append(true);

            return [];
        };

        $cache->rows(false, 'solo|500||10|2|2', $fetch);
        $cache->rows(false, 'solo|500||10|2|2', $fetch);

        self::assertCount(2, $reads);
    }

    public function testSwitchedOffItAlwaysReadsTheDatabase(): void
    {
        $cache = new TimesBoardGuestCache(new ArrayAdapter(), enabled: false);
        /** @var ArrayObject<int, true> $reads */
        $reads = new ArrayObject();
        $fetch = static function () use ($reads): array {
            $reads->append(true);

            return [];
        };

        $cache->rows(true, 'solo|500||10|0|0', $fetch);
        $cache->rows(true, 'solo|500||10|0|0', $fetch);

        self::assertCount(2, $reads);
    }
}
