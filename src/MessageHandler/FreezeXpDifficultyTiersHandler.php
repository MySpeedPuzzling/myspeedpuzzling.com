<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\FreezeXpDifficultyTiers;
use SpeedPuzzling\Web\Services\Xp\XpTierFreezer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class FreezeXpDifficultyTiersHandler
{
    public function __construct(
        private XpTierFreezer $xpTierFreezer,
    ) {
    }

    public function __invoke(FreezeXpDifficultyTiers $message): void
    {
        $this->xpTierFreezer->freezeForPlayer($message->playerId);
    }
}
