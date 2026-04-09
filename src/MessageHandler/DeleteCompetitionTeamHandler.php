<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteCompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCompetitionTeamHandler
{
    public function __construct(
        private CompetitionTeamRepository $competitionTeamRepository,
    ) {
    }

    public function __invoke(DeleteCompetitionTeam $message): void
    {
        $team = $this->competitionTeamRepository->get($message->teamId);

        $this->competitionTeamRepository->delete($team);
    }
}
