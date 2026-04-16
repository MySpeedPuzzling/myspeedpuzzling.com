<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerSkill;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PlayerSkillResult;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class PlayerHeader
{
    public null|PlayerProfile $player = null;

    public null|PlayerSkillResult $primarySkill = null;

    public function __construct(
        readonly private GetPlayerSkill $getPlayerSkill,
    ) {
    }

    #[PostMount]
    public function loadPrimarySkill(): void
    {
        $player = $this->player;
        assert($player !== null);

        if ($player->isPrivate) {
            return;
        }

        $this->primarySkill = $this->getPlayerSkill->byPlayerIdAndPiecesCount($player->playerId, 500);
    }
}
