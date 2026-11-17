<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use SpeedPuzzling\Web\Query\GetAnalyticsStudioPuzzleStats;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPuzzleStatistics;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The community statistics of a round's visible puzzles (analytics-studio.md §2.5), viewer-independent, kept in
 * `analytics_studio_cache` for 10 minutes per visible puzzle set and round category - one computation for all the
 * puzzles together (CacheInterface::get(), so a reveal watched by many is computed once). Four statements when cold:
 * the distributions and the top tens (GetAnalyticsStudioPuzzleStats), the times logged (GetPuzzleStatistics) and the
 * difficulty - called directly, not through ResolveDifficultyTiers::forViewer() (the members gate; the studio shows it
 * to everybody, analytics-studio.md D5).
 *
 * @phpstan-type Distribution array{bests: list<int>, firstTries: int, firstTryMedian: null|int, fastestFirstTry: null|int}
 * @phpstan-type PuzzleStats array{solo: Distribution, group: null|Distribution, top: list<array{place: int, seconds: int, playerId: null|string, name: null|string, code: null|string, country: null|string, private: bool}>, timesLogged: int, difficulty: null|array{score: null|float, tier: null|string, confidence: string}}
 */
readonly final class AnalyticsStudioPuzzleStatsCache
{
    public const int TTL_SECONDS = 600;

    public function __construct(
        private GetAnalyticsStudioPuzzleStats $getAnalyticsStudioPuzzleStats,
        private GetPuzzleStatistics $getPuzzleStatistics,
        private GetPuzzleDifficulty $getPuzzleDifficulty,
        private CacheInterface $analyticsStudioCache,
    ) {
    }

    /**
     * @param list<string> $puzzleIds visible puzzles only
     * @return array<string, PuzzleStats> puzzle id => its statistics
     */
    public function of(array $puzzleIds, RoundCategory $category): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $puzzleIds = array_values(array_unique(array_map('strtolower', $puzzleIds)));
        sort($puzzleIds);
        $key = 'puzzle-stats.' . $category->value . '.' . sha1(implode(',', $puzzleIds));

        /** @var array<string, PuzzleStats> $stats */
        $stats = $this->analyticsStudioCache->get($key, function (ItemInterface $item) use ($puzzleIds, $category): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->compute($puzzleIds, $category);
        });

        return $stats;
    }

    /**
     * @param list<string> $puzzleIds
     * @return array<string, PuzzleStats>
     */
    private function compute(array $puzzleIds, RoundCategory $category): array
    {
        $distributions = $this->getAnalyticsStudioPuzzleStats->distributions($puzzleIds, $category);
        $top = $this->getAnalyticsStudioPuzzleStats->top($puzzleIds);
        $statistics = $this->getPuzzleStatistics->forPuzzleList($puzzleIds);
        $difficulties = $this->getPuzzleDifficulty->forPuzzleList($puzzleIds);

        $statisticsById = [];
        foreach ($statistics as $puzzleId => $statistic) {
            $statisticsById[strtolower($puzzleId)] = $statistic;
        }
        $difficultiesById = [];
        foreach ($difficulties as $puzzleId => $difficulty) {
            $difficultiesById[strtolower($puzzleId)] = $difficulty;
        }

        $result = [];

        foreach ($puzzleIds as $puzzleId) {
            $difficulty = $difficultiesById[$puzzleId] ?? null;
            $distribution = $distributions[$puzzleId] ?? null;
            $empty = ['bests' => [], 'firstTries' => 0, 'firstTryMedian' => null, 'fastestFirstTry' => null];

            $result[$puzzleId] = [
                'solo' => $distribution['solo'] ?? $empty,
                'group' => $distribution['group'] ?? null,
                'top' => $top[$puzzleId] ?? [],
                'timesLogged' => $statisticsById[$puzzleId]->solvedTimes ?? 0,
                'difficulty' => $difficulty !== null ? [
                    'score' => $difficulty->difficultyScore !== null ? round($difficulty->difficultyScore, 3) : null,
                    'tier' => $difficulty->difficultyTier?->toApiValue(),
                    'confidence' => $difficulty->confidence->value,
                ] : null,
            ];
        }

        return $result;
    }
}
