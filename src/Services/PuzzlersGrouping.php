<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\CountryCode;
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
        $teamPlayers = self::splitInputs($teamPlayers);

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
            playerCountry: null,
            isPrivate: $player->isPrivate,
        ));

        return new PuzzlersGroup(null, $puzzlers);
    }

    /**
     * The registered players among the co-puzzler inputs, read exactly like assembleGroup() reads them -
     * for checks that must not create anything (the first-try rules).
     *
     * @param array<string> $teamPlayers
     * @return list<string>
     */
    public function registeredPlayerIds(array $teamPlayers): array
    {
        $ids = [];

        foreach (array_unique(self::splitInputs($teamPlayers)) as $playerCodeOrName) {
            $puzzler = $this->getPuzzlerFromUserInput($playerCodeOrName);

            if ($puzzler->playerId !== null) {
                $ids[] = $puzzler->playerId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * One co-puzzler input holds one person - "Anna, Ben, Clara" typed into the guest box are three guests,
     * never one guest of that name (a pair saved instead of a team of four). Every reader of the inputs splits
     * the same way: the group, the first-try rules and the pace check's head count.
     *
     * @param array<mixed> $teamPlayers
     * @return list<string>
     */
    public static function splitInputs(array $teamPlayers): array
    {
        $inputs = [];

        foreach ($teamPlayers as $input) {
            if (is_string($input) === false) {
                continue;
            }

            foreach (explode(',', $input) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $inputs[] = $part;
                }
            }
        }

        return $inputs;
    }

    private function getPuzzlerFromUserInput(string $playerCodeOrName): Puzzler
    {
        $isRegisteredPlayer = str_starts_with($playerCodeOrName, '#');

        // Can start with hashtag and contains space on the end
        $playerCodeOrName = trim($playerCodeOrName, "\# \t\n\r\0");

        try {
            // Do not look up for players when not starting with #
            if ($isRegisteredPlayer === false) {
                throw new PlayerNotFound();
            }

            $player = $this->playerRepository->getByCode($playerCodeOrName);

            return new Puzzler(
                playerId: $player->id->toString(),
                playerName: null,
                playerCode: $player->code,
                playerCountry: CountryCode::fromCode($player->country),
                isPrivate: $player->isPrivate,
            );
        } catch (PlayerNotFound) {
            return new Puzzler(
                playerId: null,
                playerName: $playerCodeOrName,
                playerCode: null,
                playerCountry: null,
                isPrivate: false,
            );
        }
    }
}
