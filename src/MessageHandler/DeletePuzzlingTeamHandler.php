<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CanNotDeletePuzzlingTeamWithResults;
use SpeedPuzzling\Web\Exceptions\NotAMemberOfPuzzlingTeam;
use SpeedPuzzling\Web\Exceptions\PuzzlingTeamNotFound;
use SpeedPuzzling\Web\Message\DeletePuzzlingTeam;
use SpeedPuzzling\Web\Repository\PuzzlingTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeletePuzzlingTeamHandler
{
    public function __construct(
        private PuzzlingTeamRepository $puzzlingTeamRepository,
    ) {
    }

    /**
     * @throws PuzzlingTeamNotFound
     * @throws NotAMemberOfPuzzlingTeam
     * @throws CanNotDeletePuzzlingTeamWithResults
     */
    public function __invoke(DeletePuzzlingTeam $message): void
    {
        $team = $this->puzzlingTeamRepository->get($message->teamId);

        if ($this->puzzlingTeamRepository->isMember($team, $message->playerId) === false) {
            throw new NotAMemberOfPuzzlingTeam();
        }

        // Only a team that was prepared and never used can go - results are never touched
        if ($this->puzzlingTeamRepository->hasResults($team)) {
            throw new CanNotDeletePuzzlingTeamWithResults();
        }

        $this->puzzlingTeamRepository->remove($team);
    }
}
