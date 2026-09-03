<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Query\GetSolveXpDisplayInfo;
use SpeedPuzzling\Web\Query\GetXpEntriesForSolve;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Results\BadgeProgress;
use SpeedPuzzling\Web\Results\SolveXpDisplayInfo;
use SpeedPuzzling\Web\Results\XpEntryLine;
use SpeedPuzzling\Web\Results\XpProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\XpReason;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Post-solve XP receipt (§1.9): additive lines rendered 1:1 from the ledger, never
 * negative — repeat/relax discounts fold into the base line's label. Pending
 * settlements show as dim informational lines. At level 50 the receipt is replaced
 * by nearest-achievement progress (member) or the waiting-achievements teaser (free).
 */
#[AsTwigComponent]
final class XpSolveReceipt
{
    public null|string $solvingTimeId = null;

    /** @var list<XpEntryLine> */
    public array $lines = [];

    public null|XpProfile $profile = null;

    public null|SolveXpDisplayInfo $info = null;

    public bool $viewerIsMember = false;

    public int $waitingCount = 0;

    /** @var list<array{type: BadgeType, progress: BadgeProgress}> */
    public array $nearestAchievements = [];

    /**
     * @param iterable<BadgeConditionInterface> $conditions
     */
    public function __construct(
        #[AutowireIterator('badge.condition')]
        readonly private iterable $conditions,
        readonly private GetXpEntriesForSolve $getXpEntriesForSolve,
        readonly private GetXpProfile $getXpProfile,
        readonly private GetSolveXpDisplayInfo $getSolveXpDisplayInfo,
        readonly private GetBadges $getBadges,
        readonly private GetPlayerStatsSnapshot $getPlayerStatsSnapshot,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[PostMount]
    public function load(): void
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->solvingTimeId === null || $viewer === null) {
            return;
        }

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false) {
            return;
        }

        try {
            $profile = $this->getXpProfile->byPlayerId($viewer->playerId);
        } catch (PlayerNotFound) {
            return;
        }

        if ($profile->optedOut) {
            return;
        }

        $info = $this->getSolveXpDisplayInfo->forPlayerAndSolvingTime($viewer->playerId, $this->solvingTimeId);

        if ($info === null) {
            // Viewer is not a participant of this solve — nothing to show them.
            return;
        }

        $this->profile = $profile;
        $this->info = $info;
        $this->viewerIsMember = $viewer->activeMembership;
        $this->lines = $this->getXpEntriesForSolve->forPlayerAndSolvingTime($viewer->playerId, $this->solvingTimeId);

        if ($this->viewerIsMember === false) {
            $this->waitingCount = count($this->getBadges->forPlayer($viewer->playerId));
        }

        if ($profile->isMaxLevel() && $this->viewerIsMember) {
            $this->nearestAchievements = $this->computeNearestAchievements($viewer->playerId);
        }
    }

    public function mode(): string
    {
        if ($this->profile === null || $this->info === null) {
            return 'hidden';
        }

        if ($this->profile->isMaxLevel()) {
            return 'max_level';
        }

        if ($this->lines !== []) {
            return 'receipt';
        }

        if ($this->info->isTimed === false && $this->info->occurrenceIndex > 1) {
            return 'relax_repeat';
        }

        // Entries are written asynchronously — the celebration component's poll refreshes this slot.
        return 'pending';
    }

    public function total(): int
    {
        $total = 0;

        foreach ($this->lines as $line) {
            $total += $line->amount;
        }

        return $total;
    }

    public function lineLabelKey(XpEntryLine $line): string
    {
        if ($line->reason === XpReason::SolveBase) {
            $info = $this->info;

            if ($info !== null && $info->isTimed === false) {
                return 'xp.line.base_relax';
            }

            return match (true) {
                $info === null || $info->occurrenceIndex <= 1 => 'xp.line.base',
                $info->occurrenceIndex === 2 => 'xp.line.base_repeat_second',
                default => 'xp.line.base_repeat_later',
            };
        }

        return 'xp.line.' . $line->reason->value;
    }

    public function showDifficultyPending(): bool
    {
        return $this->info !== null
            && $this->lines !== []
            && $this->info->isBackfill === false
            && $this->info->puzzleHasDifficultyTier === false
            && $this->hasLine(XpReason::SolveDifficultyBonus) === false
            && $this->hasLine(XpReason::DifficultySettlement) === false;
    }

    public function showSpeedPending(): bool
    {
        return $this->info !== null
            && $this->lines !== []
            && $this->info->isBackfill === false
            && $this->info->isSolo
            && $this->info->isTimed
            && $this->info->speedMedianReliable === false
            && $this->hasLine(XpReason::SolveSpeedBonus) === false
            && $this->hasLine(XpReason::SpeedSettlement) === false;
    }

    private function hasLine(XpReason $reason): bool
    {
        foreach ($this->lines as $line) {
            if ($line->reason === $reason) {
                return true;
            }
        }

        return false;
    }

    /**
     * Top progress toward not-yet-earned achievement tiers — the level-50 replacement slot.
     *
     * @return list<array{type: BadgeType, progress: BadgeProgress}>
     */
    private function computeNearestAchievements(string $playerId): array
    {
        $snapshot = $this->getPlayerStatsSnapshot->forPlayer($playerId);

        $highestEarned = [];
        foreach ($this->getBadges->forPlayer($playerId) as $badge) {
            if ($badge->tier !== null) {
                $highestEarned[$badge->type->value] = $badge->tier;
            }
        }

        $candidates = [];

        foreach ($this->conditions as $condition) {
            $type = $condition->badgeType();
            $earned = $highestEarned[$type->value] ?? null;

            if ($earned === BadgeTier::Diamond) {
                continue;
            }

            $progress = $condition->progressToNextTier($snapshot, $earned);

            if ($progress === null || $progress->percent <= 0) {
                continue;
            }

            $candidates[] = ['type' => $type, 'progress' => $progress];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['progress']->percent <=> $a['progress']->percent);

        return array_slice($candidates, 0, 2);
    }
}
