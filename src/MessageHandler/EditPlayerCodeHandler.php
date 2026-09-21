<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerCodeChange;
use SpeedPuzzling\Web\Exceptions\NonUniquePlayerCode;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditPlayerCode;
use SpeedPuzzling\Web\Repository\PlayerCodeChangeRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditPlayerCodeHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private GenerateUniquePlayerCode $generateUniquePlayerCode,
        private PlayerCodeChangeRepository $playerCodeChangeRepository,
        private ClockInterface $clock,
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

        $previousCode = $player->code;
        $player->changeCode($message->code);

        // Saving the form without touching the code is not a change
        if ($player->code !== $previousCode) {
            $this->playerCodeChangeRepository->save(new PlayerCodeChange(
                Uuid::uuid7(),
                $player,
                $previousCode,
                $player->code,
                $this->clock->now(),
            ));
        }
    }
}
