<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Message\ClaimParticipant;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ParticipantLinking\ParticipantClaims;
use SpeedPuzzling\Web\Value\ClaimOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ClaimParticipantHandler
{
    public function __construct(
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private CompetitionRepository $competitionRepository,
        private CompetitionParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private ParticipantClaims $participantClaims,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     * @throws CompetitionParticipantNotFound
     */
    public function __invoke(ClaimParticipant $message): ClaimOutcome
    {
        // Only a publicly visible event takes claims (P17) - a draft's list is nobody's to pick from
        if ($this->isCompetitionPubliclyVisible->check($message->competitionId) === false) {
            throw new CompetitionNotFound();
        }

        return $this->participantClaims->claim(
            $this->competitionRepository->get($message->competitionId),
            $this->playerRepository->get($message->playerId),
            $this->participantRepository->get($message->participantId),
            $message->releasesOwnRegistration,
        );
    }
}
