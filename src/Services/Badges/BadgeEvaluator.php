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
use SpeedPuzzling\Web\Services\Xp\XpLedger;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\XpEntryDraft;
use SpeedPuzzling\Web\Value\XpReason;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Evaluates every registered badge condition for one player and persists any newly-qualified
 * tiers. Gaps are filled — if a player jumps to tier 3 without previously holding tier 1 or 2,
 * all three rows land with the same earnedAt timestamp so history reads sensibly.
 *
 * Every newly persisted tier also grants its achievement XP exactly once (anchored by the
 * unique badge_id ledger index); achievements are never revoked, so this XP is never
 * compensated. Gap-filled tiers each grant their own XP.
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
        private XpLedger $xpLedger,
    ) {
    }

    /**
     * Returns badges newly persisted for this run. Each entry is a freshly constructed Badge
     * (not yet flushed — the Messenger doctrine_transaction middleware commits the transaction).
     *
     * @return list<Badge>
     */
    public function recalculateForPlayer(string $playerId, bool $isBackfill = false): array
    {
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

        if ($newBadges !== []) {
            $drafts = [];

            foreach ($newBadges as $badge) {
                $tier = $badge->tier === null ? null : BadgeTier::from($badge->tier);

                $drafts[] = new XpEntryDraft(
                    reason: XpReason::Achievement,
                    amount: $tier === null ? BadgeTier::SINGLE_TIER_POINTS : $tier->points(),
                    earnedAt: $badge->earnedAt,
                    // Backfilled achievements carry the backfill run time as earned_at and
                    // must not flood that week's delta leaderboard.
                    inWeeklyDelta: $isBackfill === false,
                    badgeId: $badge->id,
                );
            }

            $this->xpLedger->append($player, $drafts);
        }

        // Re-anchor the denormalized AP total on every evaluation (absolute set, not an
        // increment) — the 15-minute recalc cron thereby self-heals any drift, e.g. from
        // manually granted badges. Doctrine only issues an UPDATE when the value changed.
        $achievementPoints = 0;

        foreach ($existingBadges as $existingBadge) {
            $achievementPoints += $existingBadge->tier?->points() ?? BadgeTier::SINGLE_TIER_POINTS;
        }

        foreach ($newBadges as $newBadge) {
            $newTier = $newBadge->tier === null ? null : BadgeTier::from($newBadge->tier);
            $achievementPoints += $newTier?->points() ?? BadgeTier::SINGLE_TIER_POINTS;
        }

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
