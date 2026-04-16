<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Results\BadgeResult;
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
    ) {
    }

    #[PostMount]
    public function loadBadges(): void
    {
        if ($this->playerId === null) {
            return;
        }

        $this->badges = $this->getBadges->forPlayer($this->playerId);
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
