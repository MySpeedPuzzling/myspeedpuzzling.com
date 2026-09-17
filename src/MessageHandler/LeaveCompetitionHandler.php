<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Value\ParticipantSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class LeaveCompetitionHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LeaveCompetition $message): void
    {
        // Every row, not just one — a player who joined twice would otherwise still be going
        $participantIds = $this->getCompetitionParticipants->getPlayerConnections($message->competitionId, $message->playerId);

        foreach ($participantIds as $participantId) {
            $participant = $this->participantRepository->get($participantId);

            if ($participant->source === ParticipantSource::SelfJoined) {
                $participant->softDelete($this->clock->now());
            } else {
                $participant->disconnect();
            }
        }
    }
}
