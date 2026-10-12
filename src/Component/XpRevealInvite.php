<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Invite to the achievement reveal moment (badge_reveals) — on the membership page (where Stripe checkout lands) and
 * the free trial's welcome page, for members who still have unrevealed achievements waiting.
 */
#[AsTwigComponent]
final class XpRevealInvite
{
    public null|string $playerId = null;

    public int $unrevealedCount = 0;

    public function __construct(
        readonly private GetBadges $getBadges,
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

        if ($viewer === null || $viewer->playerId !== $this->playerId || $viewer->achievementsOptedOut) {
            return;
        }

        $this->unrevealedCount = count($this->getBadges->unrevealedForPlayer($this->playerId));
    }
}
