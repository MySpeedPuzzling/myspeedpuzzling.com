<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\DetectPlayerTimezone;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DetectPlayerTimezoneHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(DetectPlayerTimezone $message): void
    {
        $timezone = TimezoneIdentifier::normalize($message->timezone);

        if ($timezone === null) {
            return;
        }

        $player = $this->playerRepository->get($message->playerId);
        $player->fillDetectedTimezone($timezone);
    }
}
