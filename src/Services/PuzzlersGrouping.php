<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

readonly final class PuzzlersGrouping
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @param array<string> $teamPlayers
     *
     * @throws CanNotAssembleEmptyGroup
     */
    public function assembleGroup(Player $player, array $teamPlayers): null|PuzzlersGroup
    {
        if (count($teamPlayers) === 0) {
            return null;
        }

        $teamPlayers = array_unique($teamPlayers);
        $puzzlers = array_map(
            fn(string $playerCodeOrName): Puzzler => $this->getPuzzlerFromUserInput($playerCodeOrName),
            $teamPlayers,
        );

        // Filter out player itself (it is added later)
        $puzzlers = array_filter(
            $puzzlers,
            static fn(Puzzler $puzzler): bool => $puzzler->playerId !== $player->id->toString(),
        );

        if (count($puzzlers) === 0) {
            throw new CanNotAssembleEmptyGroup();
        }

        // Add self to the group (as first)
        array_unshift($puzzlers, new Puzzler(
            playerId: $player->id->toString(),
            playerName: null,
            playerCode: null,
        ));

        return new PuzzlersGroup(null, $puzzlers);
    }

    private function getPuzzlerFromUserInput(string $playerCodeOrName): Puzzler
    {
        // Can start with hashtag and contains space on the end
        $playerCodeOrName = trim($playerCodeOrName, '\# \t\n\r\0');

        try {
            $player = $this->playerRepository->getByCode($playerCodeOrName);

            return new Puzzler(
                playerId: $player->id->toString(),
                playerName: null,
                playerCode: $player->code,
            );
        } catch (PlayerNotFound) {
            return new Puzzler(
                playerId: null,
                playerName: $playerCodeOrName,
                playerCode: null,
            );
        }
    }
}
