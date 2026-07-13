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

#[AsTwigComponent]
final class BadgesProfileSection
{
    public null|string $playerId = null;

    /** @var list<BadgeResult> */
    public array $badges = [];

    public function __construct(
        readonly private GetBadges $getBadges,
        readonly private ClockInterface $clock,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[PostMount]
    public function loadBadges(): void
    {
        if ($this->playerId === null || $this->isVisible() === false) {
            return;
        }

        $this->badges = $this->getBadges->forPlayer($this->playerId);
    }

    public function isVisible(): bool
    {
        return $this->xpFeatureGate->isVisibleFor($this->retrieveLoggedUserProfile->getProfile());
    }

    /**
     * Badge is considered "new" when earned within the last 7 days — highlighted in the UI.
     */
    public function isNew(BadgeResult $badge): bool
    {
        $cutoff = $this->clock->now()->modify('-7 days');

        return $badge->earnedAt > $cutoff;
    }
}
