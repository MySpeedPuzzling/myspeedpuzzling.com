<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\NotAMemberOfPuzzlingTeam;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzlingTeamNotFound;
use SpeedPuzzling\Web\Message\RenamePuzzlingTeam;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzlingTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RenamePuzzlingTeamHandler
{
    public function __construct(
        private PuzzlingTeamRepository $puzzlingTeamRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzlingTeamNotFound
     * @throws PlayerNotFound
     * @throws NotAMemberOfPuzzlingTeam
     */
    public function __invoke(RenamePuzzlingTeam $message): void
    {
        $team = $this->puzzlingTeamRepository->get($message->teamId);
        $player = $this->playerRepository->get($message->playerId);

        // The name is shared and public: every registered member may change it, nobody else
        if ($this->puzzlingTeamRepository->isMember($team, $message->playerId) === false) {
            throw new NotAMemberOfPuzzlingTeam();
        }

        $team->rename($player, $message->name, $this->clock->now());
    }
}
