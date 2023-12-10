<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\Player;
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
     */
    public function assembleGroup(Player $player, array $teamPlayers): null|PuzzlersGroup
    {
        if (count($teamPlayers) === 0) {
            return null;
        }

        // Add self to the group
        /** @var non-empty-array<Puzzler> $puzzlers */
        $puzzlers = [
            new Puzzler(
                playerId: $player->id->toString(),
                playerName: null,
            ),
        ];

        foreach ($teamPlayers as $playerCodeOrName) {
            $puzzlers[] = $this->getPuzzlerFromUserInput($playerCodeOrName);
        }

        return new PuzzlersGroup(null, $puzzlers);
    }

    private function getPuzzlerFromUserInput(string $playerCodeOrName): Puzzler
    {
        // Can start with hashtag and contains space on the end
        $playerCodeOrName = trim($playerCodeOrName, '# \t\n\r\0\x0B');

        try {
            $player = $this->playerRepository->getByCode($playerCodeOrName);

            return new Puzzler(
                playerId: $player->id->toString(),
                playerName: null,
            );
        } catch (PlayerNotFound) {
            return new Puzzler(
                playerId: null,
                playerName: $playerCodeOrName,
            );
        }
    }
}
