<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetAchievementRarity;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * How many puzzlers hold each achievement tier, and their share of all puzzlers in percent (0-100). The same numbers
 * for every visitor, so one aggregate (GetAchievementRarity) is cached for 15 minutes - badges are evaluated on that
 * rhythm anyway. The cached value is a plain array. Single-tier achievements (Early Adopter) sit under tier 0.
 */
readonly final class AchievementRarityProvider
{
    private const int CACHE_TTL = 900;

    public function __construct(
        private GetAchievementRarity $getAchievementRarity,
        private CacheInterface $achievementRarityCache,
    ) {
    }

    /**
     * @return array<string, array<int, float>> type value → tier value → percent of puzzlers holding it
     */
    public function percentages(): array
    {
        return $this->load()['percentages'];
    }

    /**
     * @return array<string, array<int, int>> type value → tier value → number of holders
     */
    public function holders(): array
    {
        return $this->load()['holders'];
    }

    /**
     * @return array{percentages: array<string, array<int, float>>, holders: array<string, array<int, int>>}
     */
    private function load(): array
    {
        /** @var array{percentages: array<string, array<int, float>>, holders: array<string, array<int, int>>} $rarity */
        $rarity = $this->achievementRarityCache->get('achievement_rarity_v3', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            $counts = $this->getAchievementRarity->counts();
            $percentages = [];

            foreach ($counts['holders'] as $type => $tiers) {
                foreach ($tiers as $tier => $holders) {
                    $percentages[$type][$tier] = $counts['puzzlers'] > 0
                        ? min(100.0, round($holders / $counts['puzzlers'] * 100, 1))
                        : 0.0;
                }
            }

            return ['percentages' => $percentages, 'holders' => $counts['holders']];
        });

        return $rarity;
    }
}
