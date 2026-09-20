<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\PreparePuzzlingTeam;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class PreparePuzzlingTeamHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzlersGrouping $puzzlersGrouping,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws CanNotAssembleEmptyGroup
     */
    public function __invoke(PreparePuzzlingTeam $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $group = $this->puzzlersGrouping->assembleGroup($player, $message->groupPlayers);

        if ($group === null) {
            throw new CanNotAssembleEmptyGroup();
        }

        // The same people are the same team: preparing one that exists finds it, and at most names it
        $team = $this->puzzlingTeamResolver->resolve($group, preparedByPlayerId: $message->playerId);
        assert($team !== null);

        $team->nameIfUnnamed($player, $message->name, $this->clock->now());
    }
}
