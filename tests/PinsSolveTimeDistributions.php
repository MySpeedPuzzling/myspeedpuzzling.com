<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\SolveTimeDistribution;
use SpeedPuzzling\Web\Results\SolveTimeDistributionSnapshot;
use SpeedPuzzling\Web\Services\SolveTimeDistributionProvider;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Pins the "how long" guides to a known snapshot instead of the fixture database.
 *
 * The fixtures hold a few dozen solves, while a size guide only exists from 300 solo
 * solves - so the tests write production-shaped numbers straight into the
 * distribution cache. In the test env that pool is an in-memory array owned by the
 * kernel, so a pinned snapshot is seen by the client's first request (same kernel)
 * and is gone with it: nothing leaks into other tests. Pin after createClient(),
 * before the first request.
 */
trait PinsSolveTimeDistributions
{
    public const string PINNED_COMPUTED_AT = '2026-09-28 04:15:00';

    /**
     * Median seconds + solves per pieces count, shaped like production in autumn 2026.
     *
     * @var array<string, array<int, array{int, int}>>
     */
    private const array PINNED_DATA = [
        'solo' => [
            100 => [528, 6506],
            200 => [1296, 11868],
            300 => [2298, 30416],
            500 => [3894, 337720],
            1000 => [11742, 18380],
            1500 => [25518, 508],
            2000 => [39120, 347],
        ],
        'duo' => [
            100 => [432, 187],
            200 => [816, 433],
            300 => [1566, 2268],
            500 => [2682, 46741],
            1000 => [6342, 5236],
            1500 => [15294, 42],
            2000 => [27492, 29],
        ],
        'team' => [
            100 => [342, 28],
            200 => [756, 38],
            300 => [1290, 238],
            500 => [2028, 3972],
            1000 => [3864, 8778],
            1500 => [7260, 80],
            2000 => [9552, 40],
        ],
        'groups_of_3' => [
            500 => [2340, 1468],
            1000 => [5256, 1152],
        ],
        'groups_of_4' => [
            500 => [1824, 2419],
            1000 => [3690, 7396],
        ],
    ];

    /**
     * @param array<int, int> $soloSolvesOverride pieces count => solo solves, e.g. [2000 => 299]
     */
    private static function pinSolveTimeDistributions(array $soloSolvesOverride = []): void
    {
        /** @var CacheInterface $cache */
        $cache = self::getContainer()->get('solve_time_distribution_cache');

        $keys = [
            'solo' => SolveTimeDistributionProvider::cacheKey(PuzzlingType::Solo),
            'duo' => SolveTimeDistributionProvider::cacheKey(PuzzlingType::Duo),
            'team' => SolveTimeDistributionProvider::cacheKey(PuzzlingType::Team),
            'groups_of_3' => SolveTimeDistributionProvider::cacheKey(PuzzlingType::Team, 3),
            'groups_of_4' => SolveTimeDistributionProvider::cacheKey(PuzzlingType::Team, 4),
        ];

        foreach ($keys as $type => $key) {
            $distributions = [];

            foreach (self::PINNED_DATA[$type] as $pieces => [$medianSeconds, $solves]) {
                if ($type === 'solo' && isset($soloSolvesOverride[$pieces])) {
                    $solves = $soloSolvesOverride[$pieces];
                }

                $distributions[$pieces] = self::pinnedDistribution($pieces, $solves, $medianSeconds);
            }

            $snapshot = new SolveTimeDistributionSnapshot($distributions, new DateTimeImmutable(self::PINNED_COMPUTED_AT));

            $cache->delete($key);
            $cache->get($key, static fn (): SolveTimeDistributionSnapshot => $snapshot);
        }
    }

    private static function pinnedDistribution(int $pieces, int $solves, int $medianSeconds): SolveTimeDistribution
    {
        $firstAttempts = intdiv($solves, 2);

        return new SolveTimeDistribution(
            piecesCount: $pieces,
            solvesCount: $solves,
            playersCount: max(1, intdiv($solves, 10)),
            medianSeconds: $medianSeconds,
            p25Seconds: intdiv($medianSeconds * 4, 5),
            p75Seconds: intdiv($medianSeconds * 13, 10),
            p90Seconds: intdiv($medianSeconds * 8, 5),
            p10Seconds: intdiv($medianSeconds * 13, 20),
            fastestSeconds: intdiv($medianSeconds, 4),
            firstAttemptMedianSeconds: intdiv($medianSeconds * 27, 25),
            firstAttemptCount: $firstAttempts,
            notFirstAttemptMedianSeconds: intdiv($medianSeconds * 23, 25),
            notFirstAttemptCount: $solves - $firstAttempts,
        );
    }
}
