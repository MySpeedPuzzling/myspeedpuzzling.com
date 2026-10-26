<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\ExpireMessagingMutes;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Loads the players whose mute has ended and lifts it through the entity - mutes are a moderator's action, so a run
 * finds a handful at most and no bulk UPDATE is called for. Nothing else is written: a mute running out is no
 * moderator's decision (unlike UnmuteUser, which records one).
 */
#[AsMessageHandler]
readonly final class ExpireMessagingMutesHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int How many mutes were lifted
     */
    public function __invoke(ExpireMessagingMutes $message): int
    {
        $players = $this->playerRepository->findWithMessagingMuteEndedBefore($this->clock->now());

        foreach ($players as $player) {
            $player->unmuteMessaging();
        }

        return count($players);
    }
}
