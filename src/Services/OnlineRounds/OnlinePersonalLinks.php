<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use DateTimeImmutable;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Value\OnlineToken;

/**
 * Gives a personal link (OnlineToken, docs/features/online-competitions/README.md §5.4) to every participant of an event
 * who is going and has none yet - when online timing is turned on (EnableOnlineRound) and for the people added later
 * (GenerateOnlineTokens). Removed and waitlisted participants get none: a link is the access right, and they do not
 * compete. A link once given stays (RegenerateOnlineToken replaces it). Runs inside those handlers, under the event's
 * participants lock - two runs never give one person two links.
 */
readonly final class OnlinePersonalLinks
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
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
}
