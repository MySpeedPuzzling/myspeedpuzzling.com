<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionTeamOfAnotherRound;
use SpeedPuzzling\Web\Message\AssignParticipantToTeam;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AssignParticipantToTeamHandler
{
    public function __construct(
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $competitionTeamRepository,
    ) {
    }

    /**
     * @throws CompetitionTeamOfAnotherRound
     */
    public function __invoke(AssignParticipantToTeam $message): void
    {
        $participantRound = $this->participantRoundRepository->get($message->participantRoundId);

        if ($message->teamId === null) {
            $participantRound->removeFromTeam();
        } else {
            $team = $this->competitionTeamRepository->get($message->teamId);

            if ($team->round->id->equals($participantRound->round->id) === false) {
                throw new CompetitionTeamOfAnotherRound();
            }

            $participantRound->assignToTeam($team);
        }
    }
}
