<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetXpEntriesForSolve;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Results\XpProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Recap-page celebration bridge (§1.9): XP is awarded asynchronously, so this lazy
 * Live Component renders the receipt slot, polls exactly once to pick up async-granted
 * XP/achievements, then pops the celebration. Queueing rule: level-up interstitial
 * first (full-screen, tap anywhere to skip), achievement toast underneath.
 *
 * Level 50 is golden for EVERYONE (never paywalled), followed by a fork screen:
 * members enter the AP ladder, free players see the same ladder read-only + a
 * membership CTA.
 *
 * Level-up detection is ledger-derived and race-free: the solve's own XP total is
 * subtracted from the player's total to reconstruct the pre-solve level.
 */
#[AsLiveComponent]
final class XpRecapCelebration
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $solvingTimeId = '';

    #[LiveProp]
    public bool $checked = false;

    #[LiveProp]
    public bool $celebrationDismissed = false;

    private null|XpProfile $loadedProfile = null;

    private bool $profileLoaded = false;

    public function __construct(
        readonly private GetXpProfile $getXpProfile,
        readonly private GetXpEntriesForSolve $getXpEntriesForSolve,
        readonly private GetBadges $getBadges,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ClockInterface $clock,
    ) {
    }

    #[LiveAction]
    public function checkProgress(): void
    {
        $this->checked = true;
    }

    #[LiveAction]
    public function dismissCelebration(): void
    {
        $this->celebrationDismissed = true;
        $this->checked = true;
    }

    public function isVisible(): bool
    {
        return $this->profile() !== null;
    }

    public function profile(): null|XpProfile
    {
        if ($this->profileLoaded) {
            return $this->loadedProfile;
        }

        $this->profileLoaded = true;

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null || $this->solvingTimeId === '') {
            return null;
        }

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false) {
            return null;
        }

        try {
            $profile = $this->getXpProfile->byPlayerId($viewer->playerId);
        } catch (PlayerNotFound) {
            return null;
        }

        if ($profile->optedOut) {
            return null;
        }

        return $this->loadedProfile = $profile;
    }

    public function viewerIsMember(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile()?->activeMembership === true;
    }

    /**
     * True when THIS solve's XP pushed the player over a level boundary.
     */
    public function showLevelUp(): bool
    {
        if ($this->celebrationDismissed) {
            return false;
        }

        $profile = $this->profile();

        if ($profile === null) {
            return false;
        }

        $solveTotal = $this->getXpEntriesForSolve->totalForPlayerAndSolvingTime($profile->playerId, $this->solvingTimeId);

        if ($solveTotal <= 0) {
            return false;
        }

        return LevelTable::levelForXp($profile->xpTotal) > LevelTable::levelForXp($profile->xpTotal - $solveTotal);
    }

    public function isMaxLevelCelebration(): bool
    {
        return ($this->profile()?->isMaxLevel() ?? false) && $this->showLevelUp();
    }

    /**
     * Newest achievement earned in the last few minutes — the async badge evaluation
     * usually lands between page render and the single poll. Members only (§1.7).
     */
    public function recentBadge(): null|BadgeResult
    {
        if ($this->profile() === null || $this->viewerIsMember() === false) {
            return null;
        }

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return null;
        }

        $cutoff = $this->clock->now()->modify('-3 minutes');
        $newest = null;

        foreach ($this->getBadges->forPlayer($viewer->playerId) as $badge) {
            if ($badge->earnedAt < $cutoff) {
                continue;
            }

            if ($newest === null || $badge->earnedAt > $newest->earnedAt) {
                $newest = $badge;
            }
        }

        return $newest;
    }

    public function currentLevel(): int
    {
        return $this->profile()->level ?? 1;
    }

    public function earnedAtIso(DateTimeImmutable $moment): string
    {
        return $moment->format(DATE_ATOM);
    }
}
