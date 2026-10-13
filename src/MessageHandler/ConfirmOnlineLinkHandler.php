<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Message\ConfirmOnlineLink;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Marks the personal link as the connected player's own (CompetitionParticipant::confirmOnlineLink()) - only while the
 * participant is connected to exactly that player; a participant who is not (any more) theirs stays as it is. Once
 * confirmed for the current connection, nothing is written again.
 */
#[AsMessageHandler]
readonly final class ConfirmOnlineLinkHandler
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool the link is the player's confirmed own now
     *
     * @throws CompetitionParticipantNotFound
     */
    public function __invoke(ConfirmOnlineLink $message): bool
    {
        $participant = $this->competitionParticipantRepository->getOfCompetition($message->competitionId, $message->participantId);
        // As it is now, under the event's lock - the connect a moment before is seen, a stale read is not
        $this->competitionParticipantRepository->refresh($participant);

        if ($participant->isDeleted() || $participant->player?->id->toString() !== strtolower($message->playerId)) {
            return false;
        }

        if ($participant->hasConfirmedOnlineLink() === false) {
            $participant->confirmOnlineLink($this->clock->now());
        }

        return true;
    }
}
