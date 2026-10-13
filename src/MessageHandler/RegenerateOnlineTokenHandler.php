<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineTimingNotUsed;
use SpeedPuzzling\Web\Exceptions\ParticipantIsWaitlisted;
use SpeedPuzzling\Web\Message\RegenerateOnlineToken;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "New link" (docs/features/online-competitions/README.md §5.4): the participant gets a new personal link and the old
 * one stops working at once - also for a participant who had none yet (their first link). Only for a participant who
 * is going (a removed one is not found, a waitlisted one holds no spot - ParticipantIsWaitlisted), only at an event a
 * round of which uses online timing (OnlineTimingNotUsed).
 *
 * The row is read again under the event's lock: the caller may have read it before (its token is never the one
 * replaced). Returns the token it replaced - the participant API's cached heartbeat lookup of it
 * (OnlineAccessLight::forget()) is the caller's to drop once this is committed: dropped inside the transaction, a
 * heartbeat in between would cache the old link again.
 */
#[AsMessageHandler]
readonly final class RegenerateOnlineTokenHandler
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return null|string the token replaced (null: the participant had none)
     *
     * @throws CompetitionParticipantNotFound
     * @throws ParticipantIsWaitlisted
     * @throws OnlineTimingNotUsed
     */
    public function __invoke(RegenerateOnlineToken $message): null|string
    {
        $participant = $this->competitionParticipantRepository->getOfCompetition($message->competitionId, $message->participantId);
        $this->competitionParticipantRepository->refresh($participant);

        if ($participant->isDeleted()) {
            throw new CompetitionParticipantNotFound();
        }

        if ($participant->isGoing() === false) {
            throw new ParticipantIsWaitlisted();
        }

        if ($this->onlineRoundRepository->ofCompetition($participant->competition->id->toString()) === []) {
            throw new OnlineTimingNotUsed();
        }

        $replaced = $participant->onlineToken;
        $participant->assignOnlineToken(OnlineToken::generate(), $this->clock->now());

        return $replaced;
    }
}
