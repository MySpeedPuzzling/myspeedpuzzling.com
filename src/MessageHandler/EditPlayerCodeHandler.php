<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\NonUniquePlayerCode;
use SpeedPuzzling\Web\Message\EditPlayerCode;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditPlayerCodeHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private GenerateUniquePlayerCode $generateUniquePlayerCode,
    ) {
    }

    /**
     * @throws NonUniquePlayerCode
     * @throws PlayerNotFound
     */
    public function __invoke(EditPlayerCode $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $isAvailable = $this->generateUniquePlayerCode->isCodeAvailable($message->code, $message->playerId);

        if ($isAvailable === false) {
            throw new NonUniquePlayerCode();
        }

        $player->changeCode($message->code);
    }
}
