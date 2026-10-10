<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetSolveXpDisplayInfo;
use SpeedPuzzling\Web\Query\GetXpEntriesForSolve;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Results\SolveXpDisplayInfo;
use SpeedPuzzling\Web\Results\XpEntryLine;
use SpeedPuzzling\Web\Results\XpProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\LevelTable;
use SpeedPuzzling\Web\Value\RecapProgress;
use SpeedPuzzling\Web\Value\XpReason;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The live part of the recap's progress card (docs/features/xp-levels/launch-2026-10.md, "After logging a time"):
 * the achievements this time earned and the level line. The page renders the card around it, with the rows that are
 * known at render time (place on the puzzle, MSP Rating / skill tier).
 *
 * XP and achievements are awarded asynchronously (AwardXpForSolvingTime, RecalculateBadgesForPlayer - the badge
 * message is queued first), so while the solve has no XP lines yet the component polls with a growing delay
 * (POLL_DELAYS_MS) and then says the XP is still on its way. The render that shows the XP is the last one: nothing
 * re-renders under the achievement reveal or the level-up celebration afterwards.
 *
 * Every registered member of a pair/team time sees their own lines, level and achievements (the viewer, never the
 * tracker). Achievement names are for members only; a free player learns that this time earned one. Nothing at all
 * while XpFeatureGate hides the progression from the viewer.
 */
#[AsLiveComponent]
final class XpRecapCelebration
{
    use DefaultActionTrait;

    /** About 30 s of polling in total, quick at first: the worker usually answers within a second or two */
    public const array POLL_DELAYS_MS = [1200, 2000, 3000, 5000, 8000, 11000];

    #[LiveProp]
    public string $solvingTimeId = '';

    /** Set by the page: the time was saved a moment ago (RecapProgress::isFresh), so a level-up may celebrate */
    #[LiveProp]
    public bool $celebrate = false;

    #[LiveProp]
    public int $attempts = 0;

    private bool $loaded = false;

    private null|XpProfile $loadedProfile = null;

    private null|SolveXpDisplayInfo $loadedInfo = null;

    /** @var list<XpEntryLine> */
    private array $loadedLines = [];

    /** @var null|list<BadgeResult> */
    private null|array $loadedAchievements = null;

    public function __construct(
        readonly private GetXpProfile $getXpProfile,
        readonly private GetBadges $getBadges,
        readonly private GetXpEntriesForSolve $getXpEntriesForSolve,
        readonly private GetSolveXpDisplayInfo $getSolveXpDisplayInfo,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[LiveAction]
    public function checkProgress(): void
    {
        $this->attempts++;
    }

    public function isVisible(): bool
    {
        return $this->profile() !== null;
    }

    public function profile(): null|XpProfile
    {
        $this->load();

        return $this->loadedProfile;
    }

    public function info(): null|SolveXpDisplayInfo
    {
        $this->load();

        return $this->loadedInfo;
    }

    /**
     * @return list<XpEntryLine>
     */
    public function lines(): array
    {
        $this->load();

        return $this->loadedLines;
    }

    /**
     * receipt (XP counted), relax_repeat (counted, worth nothing on purpose), pending (still polling), late (polling
     * gave up - the worker is slow), hidden
     */
    public function mode(): string
    {
        $info = $this->info();

        if ($this->profile() === null || $info === null) {
            return 'hidden';
        }

        if ($this->lines() !== []) {
            return 'receipt';
        }

        if ($info->isTimed === false && $info->occurrenceIndex > 1) {
            return 'relax_repeat';
        }

        return $this->attempts < count(self::POLL_DELAYS_MS) ? 'pending' : 'late';
    }

    public function pollDelay(): null|int
    {
        if ($this->mode() !== 'pending') {
            return null;
        }

        return self::POLL_DELAYS_MS[$this->attempts] ?? null;
    }

    public function viewerIsMember(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile()?->activeMembership === true;
    }

    public function solveXp(): int
    {
        $total = 0;

        foreach ($this->lines() as $line) {
            $total += $line->amount;
        }

        return $total;
    }

    /** The level before this solve's XP - the ledger is the truth, the player's total minus this solve */
    public function previousLevel(): int
    {
        $profile = $this->profile();

        if ($profile === null) {
            return 1;
        }

        return LevelTable::levelForXp(max(0, $profile->xpTotal - $this->solveXp()));
    }

    public function leveledUp(): bool
    {
        $profile = $this->profile();

        return $profile !== null && $this->solveXp() > 0 && $profile->level > $this->previousLevel();
    }

    public function isMilestoneLevelUp(): bool
    {
        $profile = $this->profile();

        return $profile !== null && $this->leveledUp() && RecapProgress::isMilestoneLevel($profile->level);
    }

    /** Bar position before this solve (0-1), so the bar fills from there; 0 after a level-up */
    public function previousProgress(): float
    {
        $profile = $this->profile();

        if ($profile === null || $this->leveledUp()) {
            return 0.0;
        }

        return LevelTable::progressToNext(max(0, $profile->xpTotal - $this->solveXp())) ?? 1.0;
    }

    /** XP still needed for the next level and the size of the current level */
    public function levelSpan(): int
    {
        $profile = $this->profile();

        if ($profile === null || $profile->isMaxLevel()) {
            return 0;
        }

        return LevelTable::xpForLevel($profile->level + 1) - LevelTable::xpForLevel($profile->level);
    }

    /**
     * The tiers this solve earned (members see them, free players only learn that there is one). Not before the XP is
     * counted: the badge message is queued before the XP one, so once the XP is there, so are the achievements.
     *
     * @return list<BadgeResult>
     */
    public function achievements(): array
    {
        if ($this->loadedAchievements !== null) {
            return $this->loadedAchievements;
        }

        $profile = $this->profile();

        if ($profile === null || in_array($this->mode(), ['receipt', 'relax_repeat', 'late'], true) === false) {
            return $this->loadedAchievements = [];
        }

        return $this->loadedAchievements = $this->getBadges->earnedWithSolve($profile->playerId, $this->solvingTimeId);
    }

    public function pointsOf(BadgeResult $badge): int
    {
        return $badge->tier?->points() ?? BadgeTier::SINGLE_TIER_POINTS;
    }

    /** Gold and above (and single-tier achievements) get the bigger reveal */
    public function isBigMoment(BadgeResult $badge): bool
    {
        return $badge->tier === null || $badge->tier->value >= BadgeTier::Gold->value;
    }

    public function lineLabelKey(XpEntryLine $line): string
    {
        if ($line->reason === XpReason::SolveBase) {
            $info = $this->info();

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
        $info = $this->info();

        return $info !== null
            && $this->lines() !== []
            && $info->isBackfill === false
            && $info->puzzleHasDifficultyTier === false
            && $this->hasLine(XpReason::SolveDifficultyBonus) === false
            && $this->hasLine(XpReason::DifficultySettlement) === false;
    }

    public function showSpeedPending(): bool
    {
        $info = $this->info();

        return $info !== null
            && $this->lines() !== []
            && $info->isBackfill === false
            && $info->isSolo
            && $info->isTimed
            && $info->speedMedianReliable === false
            && $this->hasLine(XpReason::SolveSpeedBonus) === false
            && $this->hasLine(XpReason::SpeedSettlement) === false;
    }

    private function hasLine(XpReason $reason): bool
    {
        foreach ($this->lines() as $line) {
            if ($line->reason === $reason) {
                return true;
            }
        }

        return false;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null || $this->solvingTimeId === '') {
            return;
        }

        // Launch ready or admin (xp-system, docs/features/feature_flags.md) - otherwise the recap shows no progression
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

        // Null when the viewer is not a participant of this time - nothing of theirs to show
        $info = $this->getSolveXpDisplayInfo->forPlayerAndSolvingTime($viewer->playerId, $this->solvingTimeId);

        if ($info === null) {
            return;
        }

        $this->loadedProfile = $profile;
        $this->loadedInfo = $info;
        $this->loadedLines = $this->getXpEntriesForSolve->forPlayerAndSolvingTime($viewer->playerId, $this->solvingTimeId);
    }
}
