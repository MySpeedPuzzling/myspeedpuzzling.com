<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Message\CreateCompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CreateCompetitionTeamHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionTeamRepository $competitionTeamRepository,
    ) {
    }

    public function __invoke(CreateCompetitionTeam $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $team = new CompetitionTeam(
            id: $message->teamId,
            round: $round,
            name: $message->name,
        );

        $this->competitionTeamRepository->save($team);
    }
}
