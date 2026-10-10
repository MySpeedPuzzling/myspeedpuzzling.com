<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetAchievementRarity;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Share of puzzlers holding each achievement tier, in percent (0-100). The same numbers for every visitor, so they
 * are cached for 15 minutes - badges are evaluated on that rhythm anyway. The cached value is a plain array.
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
        /** @var array<string, array<int, float>> $percentages */
        $percentages = $this->achievementRarityCache->get('achievement_rarity_v1', function (ItemInterface $item): array {
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

            return $percentages;
        });

        return $percentages;
    }
}
