<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetXpLaunchRevealBadges;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Results\XpProfile;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The first step of the XP launch reveal inside the launch announcement modal
 * (templates/modals/announcements/_xp_launch_reveal.html.twig): the level medallion assembles by itself and the XP
 * counts up; members then continue to the achievement deck, free players get the member tease. Its queries run only
 * on the one page view that shows the modal - once per player, ever.
 */
#[AsTwigComponent]
final class XpLaunchRevealModal
{
    public string $playerId = '';

    public bool $isMember = false;

    public null|XpProfile $xpProfile = null;

    public int $achievementsCount = 0;

    public function __construct(
        readonly private GetXpProfile $getXpProfile,
        readonly private GetXpLaunchRevealBadges $getXpLaunchRevealBadges,
    ) {
    }

    #[PostMount]
    public function load(): void
    {
        $this->xpProfile = $this->getXpProfile->byPlayerId($this->playerId);
        $this->achievementsCount = count($this->getXpLaunchRevealBadges->forPlayer($this->playerId));
    }
}
