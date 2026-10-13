<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Query\EventIsOnline;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Value\OnlineToken;

/**
 * Gives personal links (OnlineToken, docs/features/online-competitions/README.md §5.4) - only to participants who are
 * going (removed and waitlisted ones get none: a link is the access right, and they do not compete), only one each (a
 * link once given stays - RegenerateOnlineToken replaces it):
 * - giveMissing(): everybody going without one - when online timing is turned on (EnableOnlineRound) and for the people
 *   the organiser adds later, when they open the links page, its CSV or QR sheet (GenerateOnlineTokens);
 * - giveOwnLink(): a participant whose row is their player's own (the link confirmed - "I'm going",
 *   JoinCompetitionHandler) gets theirs the moment they go: at the join, or when promoted from the waitlist
 *   (PromoteParticipantFromWaitlistHandler) - nobody hands it to them, the event page does ("Open my competition page").
 * Runs inside those handlers, under the event's participants lock - two runs never give one person two links.
 */
readonly final class OnlinePersonalLinks
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private OnlineRoundRepository $onlineRoundRepository,
    ) {
    }

    /**
     * @return int how many participants got a link
     */
    public function giveMissing(string $competitionId, DateTimeImmutable $now): int
    {
        $given = 0;

        foreach ($this->competitionParticipantRepository->goingWithoutOnlineToken($competitionId) as $participant) {
            $participant->assignOnlineToken(OnlineToken::generate(), $now);
            $given++;
        }

        return $given;
    }

    /**
     * @return bool a link was given - the row is the player's own, they are going, had none, and a round of the event
     *              uses online timing
     */
    public function giveOwnLink(CompetitionParticipant $participant, DateTimeImmutable $now): bool
    {
        if ($participant->hasConfirmedOnlineLink() === false || $participant->isGoing() === false || $participant->onlineToken !== null) {
            return false;
        }

        // An in-person event has no online timing (EventIsOnline) - not even a statement to ask
        if (EventIsOnline::of($participant->competition) === false) {
            return false;
        }

        if ($this->onlineRoundRepository->ofCompetition($participant->competition->id->toString()) === []) {
            return false;
        }

        $participant->assignOnlineToken(OnlineToken::generate(), $now);

        return true;
    }
}
