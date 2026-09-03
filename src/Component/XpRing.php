<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Results\XpProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Avatar/icon XP ring — the shared visual identity element (§1.9): thin progress ring,
 * level chip and optional progress bar. Milestone ring styling is CSS-only, intensifying
 * at levels 10/20/30/40 and golden at 50.
 *
 * Self-gating: renders its inner content unchanged (no ring, no numbers) whenever the
 * xp-system flag hides XP from the viewer, the subject opted out of the experience
 * system, or the subject's profile is private and the viewer is somebody else.
 */
#[AsTwigComponent]
final class XpRing
{
    public null|string $playerId = null;

    /** Ambient variant (site header): quiet full ring, no chip, no numbers. */
    public bool $ambient = false;

    /** Show the progress bar toward the next level under the chip (profile page). */
    public bool $showProgress = false;

    public null|XpProfile $profile = null;

    public function __construct(
        readonly private GetXpProfile $getXpProfile,
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

        try {
            $profile = $this->getXpProfile->byPlayerId($this->playerId);
        } catch (PlayerNotFound) {
            return;
        }

        if ($profile->optedOut) {
            return;
        }

        if ($profile->private && $viewer?->playerId !== $this->playerId) {
            return;
        }

        $this->profile = $profile;
    }

    public function isVisible(): bool
    {
        return $this->profile !== null;
    }

    public function milestoneClass(): string
    {
        $level = $this->profile->level ?? 1;

        return match (true) {
            $level >= LevelTable::MAX_LEVEL => 'xp-ring-milestone-50',
            $level >= 40 => 'xp-ring-milestone-40',
            $level >= 30 => 'xp-ring-milestone-30',
            $level >= 20 => 'xp-ring-milestone-20',
            $level >= 10 => 'xp-ring-milestone-10',
            default => '',
        };
    }

    public function progressValue(): float
    {
        if ($this->profile === null) {
            return 0.0;
        }

        return $this->profile->progressToNext() ?? 1.0;
    }
}
