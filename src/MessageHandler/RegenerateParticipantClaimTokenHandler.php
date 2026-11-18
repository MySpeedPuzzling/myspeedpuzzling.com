<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\ParticipantAlreadyLinked;
use SpeedPuzzling\Web\Message\RegenerateParticipantClaimToken;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "New link" (docs/features/competitions-management/participant-linking.md §5.2, D5): a new personal claim link for a
 * person on the list who is not linked - the old link stops working at once (also their first link). The row is read
 * again under the event's lock; a removed participant is not found, a linked one refused (ParticipantAlreadyLinked).
 */
#[AsMessageHandler]
readonly final class RegenerateParticipantClaimTokenHandler
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
    ) {
    }

    /**
     * @return string the new token
     *
     * @throws CompetitionParticipantNotFound
     * @throws ParticipantAlreadyLinked
     */
    public function __invoke(RegenerateParticipantClaimToken $message): string
    {
        $participant = $this->competitionParticipantRepository->getOfCompetition($message->competitionId, $message->participantId);
        $this->competitionParticipantRepository->refresh($participant);

        if ($participant->isDeleted()) {
            throw new CompetitionParticipantNotFound();
        }

        if ($participant->player !== null) {
            throw new ParticipantAlreadyLinked();
        }

        $token = OnlineToken::generate();
        $participant->assignClaimToken($token);

        return $token;
    }
}
