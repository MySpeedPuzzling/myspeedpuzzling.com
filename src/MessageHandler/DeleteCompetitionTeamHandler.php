<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteCompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The members stay in the round, without a team - nothing else about them changes. Removed (soft-deleted)
 * participants still pointing at the team are let go too: the page hides them, so a team can look empty
 * while somebody is still in it (WEB-D5).
 */
#[AsMessageHandler]
readonly final class DeleteCompetitionTeamHandler
{
    public function __construct(
        private CompetitionTeamRepository $competitionTeamRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
    ) {
    }

    public function __invoke(DeleteCompetitionTeam $message): void
    {
        $team = $this->competitionTeamRepository->get($message->teamId);

        // Doctrine writes these updates before the delete, all in the handler's one transaction
        foreach ($this->participantRoundRepository->findByTeam($team) as $participantRound) {
            $participantRound->removeFromTeam();
        }

        $this->competitionTeamRepository->delete($team);
    }
}
