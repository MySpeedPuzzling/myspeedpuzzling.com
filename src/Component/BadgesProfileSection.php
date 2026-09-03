<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Profile achievements strip, §1.7 visibility matrix:
 *
 *  - subject has membership → full medallions for every allowed viewer;
 *    the OWNER additionally gets the first-click reveal flip on fresh badges
 *  - subject is free + viewing own profile → locked strip + "N achievements
 *    waiting for you" teaser + membership CTA
 *  - subject is free + anyone else viewing → nothing
 *  - private profiles render only for their owner
 *  - while the xp-system flag is active, admins only (renders nothing otherwise)
 */
#[AsTwigComponent]
final class BadgesProfileSection
{
    public null|string $playerId = null;

    public bool $subjectHasMembership = false;

    public bool $subjectIsPrivate = false;

    /** @var list<BadgeResult> */
    public array $badges = [];

    public int $waitingCount = 0;

    public bool $ownProfile = false;

    private bool $viewerAllowed = false;

    public function __construct(
        readonly private GetBadges $getBadges,
        readonly private ClockInterface $clock,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[PostMount]
    public function load(): void
    {
        if ($this->playerId === null) {
            return;
        }

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false) {
            return;
        }

        $this->ownProfile = $viewer !== null && $viewer->playerId === $this->playerId;

        if ($this->subjectIsPrivate && $this->ownProfile === false) {
            return;
        }

        $this->viewerAllowed = true;

        if ($this->subjectHasMembership) {
            $this->badges = $this->getBadges->forPlayer($this->playerId);

            return;
        }

        if ($this->ownProfile) {
            $this->waitingCount = count($this->getBadges->forPlayer($this->playerId));
        }
    }

    /**
     * detail = medallions · teaser = locked strip for the free owner · hidden = nothing
     */
    public function mode(): string
    {
        if ($this->viewerAllowed === false) {
            return 'hidden';
        }

        if ($this->subjectHasMembership) {
            return 'detail';
        }

        return $this->ownProfile ? 'teaser' : 'hidden';
    }

    /**
     * Badge is considered "new" when earned within the last 7 days — highlighted in the UI.
     */
    public function isNew(BadgeResult $badge): bool
    {
        $cutoff = $this->clock->now()->modify('-7 days');

        return $badge->earnedAt > $cutoff;
    }

    /**
     * The first-click reveal moment belongs to the badge owner only — everyone else
     * always sees the finished medallion.
     */
    public function needsReveal(BadgeResult $badge): bool
    {
        return $this->ownProfile && $badge->isRevealed() === false && $badge->id !== null;
    }
}
