<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Badges;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface;
use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Repository\BadgeRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\BadgeResult;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Evaluates every registered badge condition for one player and persists any newly-qualified
 * tiers. Gaps are filled — if a player jumps to tier 3 without previously holding tier 1 or 2,
 * all three rows land with the same earnedAt timestamp so history reads sensibly.
 */
readonly class BadgeEvaluator
{
    /**
     * @param iterable<BadgeConditionInterface> $conditions
     */
    public function __construct(
        #[AutowireIterator('badge.condition')]
        private iterable $conditions,
        private GetPlayerStatsSnapshot $getPlayerStatsSnapshot,
        private GetBadges $getBadges,
        private BadgeRepository $badgeRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Returns badges newly persisted for this run. Each entry is a freshly constructed Badge
     * (not yet flushed — the Messenger doctrine_transaction middleware commits the transaction).
     *
     * @return list<Badge>
     */
    public function recalculateForPlayer(string $playerId): array
    {
        try {
            $player = $this->playerRepository->get($playerId);
        } catch (PlayerNotFound) {
            return [];
        }

        $snapshot = $this->getPlayerStatsSnapshot->forPlayer($playerId);
        $alreadyEarned = $this->earnedTierMap($this->getBadges->forPlayer($playerId));
        $now = $this->clock->now();
        $newBadges = [];

        foreach ($this->conditions as $condition) {
            $type = $condition->badgeType();

            foreach ($condition->qualifiedTiers($snapshot) as $tier) {
                if (isset($alreadyEarned[$type->value][$tier->value])) {
                    continue;
                }

                $badge = Badge::earn($player, $type, $now, $tier);
                $this->badgeRepository->save($badge);
                $alreadyEarned[$type->value][$tier->value] = true;
                $newBadges[] = $badge;
            }
        }

        return $newBadges;
    }

    /**
     * @param list<BadgeResult> $badges
     * @return array<string, array<int, true>>
     */
    private function earnedTierMap(array $badges): array
    {
        $map = [];

        foreach ($badges as $badge) {
            if ($badge->tier === null) {
                continue;
            }

            $map[$badge->type->value][$badge->tier->value] = true;
        }

        return $map;
    }
}
