<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Message\SoftDeleteCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SoftDeleteCompetitionParticipantHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private ClockInterface $clock,
        private OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    /**
     * @throws OfficialResultsProtected somebody holding official results (theirs, or their pair's/team's) stays
     */
    public function __invoke(SoftDeleteCompetitionParticipant $message): void
    {
        $participant = $this->participantRepository->get($message->participantId);

        if ($this->officialResultsGuard->participantHasOfficialData($participant->id->toString())) {
            throw new OfficialResultsProtected(OfficialResultsProtected::PARTICIPANT_HAS_RESULT);
        }

        $participant->softDelete($this->clock->now());
    }
}
