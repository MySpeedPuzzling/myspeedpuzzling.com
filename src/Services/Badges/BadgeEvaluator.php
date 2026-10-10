<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Badges;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface;
use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Repository\BadgeRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Services\Progression\PlayerProgressionLock;
use SpeedPuzzling\Web\Value\BadgeTier;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Evaluates every registered badge condition for one player and brings the stored tiers to what the player's CURRENT
 * records qualify for: newly qualified tiers are persisted (gaps filled - a player who jumps to tier 3 without tier 1
 * or 2 gets all three rows with the same earnedAt), tiers the records no longer qualify for are removed (results
 * deleted, marked as needing verification, merged away...). Achievements follow the current records like XP does -
 * logging times, earning a tier and deleting the times keeps nothing (docs/features/badges.md, path independence).
 * Silent: nobody is told about a removed tier. Achievement types without a condition (the admin-granted Early
 * Adopter, `supporter`) are never touched.
 *
 * Achievements grant Achievement Points only, never XP (docs/features/xp-levels/launch-2026-10.md: every currency is
 * fed by one kind of activity - XP by logged puzzles alone).
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
        private PlayerProgressionLock $playerProgressionLock,
        private Connection $database,
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
        // Two evaluations of one player at once would both insert the same tier, or count points from rows the other
        // one is removing
        $this->playerProgressionLock->lock([$playerId]);

        try {
            $player = $this->playerRepository->get($playerId);
        } catch (PlayerNotFound) {
            return [];
        }

        $snapshot = $this->getPlayerStatsSnapshot->forPlayer($playerId);
        $existingBadges = $this->getBadges->allEarnedTiers($playerId);
        $alreadyEarned = $this->earnedTierMap($existingBadges);
        $now = $this->clock->now();
        $newBadges = [];
        /** @var array<string, true> $removedBadgeIds */
        $removedBadgeIds = [];

        foreach ($this->conditions as $condition) {
            $type = $condition->badgeType();
            $qualified = [];

            foreach ($condition->qualifiedTiers($snapshot) as $tier) {
                $qualified[$tier->value] = true;

                if (isset($alreadyEarned[$type->value][$tier->value])) {
                    continue;
                }

                $badge = Badge::earn($player, $type, $now, $tier);
                $this->badgeRepository->save($badge);
                $alreadyEarned[$type->value][$tier->value] = true;
                $newBadges[] = $badge;
            }

            foreach ($existingBadges as $existingBadge) {
                if ($existingBadge->type !== $type || $existingBadge->tier === null || $existingBadge->id === null) {
                    continue;
                }

                if (isset($qualified[$existingBadge->tier->value])) {
                    continue;
                }

                // The records no longer qualify - the tier goes, with its reveal state; earned again later, it is new
                $this->badgeRepository->delete($this->badgeRepository->get($existingBadge->id));
                $removedBadgeIds[$existingBadge->id] = true;
            }
        }

        // Re-anchor the denormalized AP total on every evaluation (absolute set, not an
        // increment) — the daily recalc cron thereby self-heals any drift, e.g. from
        // manually granted badges.
        $achievementPoints = 0;

        foreach ($existingBadges as $existingBadge) {
            if ($existingBadge->id !== null && isset($removedBadgeIds[$existingBadge->id])) {
                continue;
            }

            $achievementPoints += $existingBadge->tier?->points() ?? BadgeTier::SINGLE_TIER_POINTS;
        }

        foreach ($newBadges as $newBadge) {
            $newTier = $newBadge->tier === null ? null : BadgeTier::from($newBadge->tier);
            $achievementPoints += $newTier?->points() ?? BadgeTier::SINGLE_TIER_POINTS;
        }

        // Written here too, not only through the entity: an entity loaded before the lock may hold an older total
        // than the database, and the unit of work only writes what differs from what it loaded
        $this->database->executeStatement(
            'UPDATE player SET achievement_points = :points WHERE id = :playerId AND achievement_points != :points',
            ['points' => $achievementPoints, 'playerId' => $playerId],
        );
        $player->updateAchievementPoints($achievementPoints);

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
