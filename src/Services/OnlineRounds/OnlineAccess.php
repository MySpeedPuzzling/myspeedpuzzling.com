<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Value\OnlineAccessContext;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * What a personal link opens (docs/features/online-competitions/README.md §5.4): the token is the access right - no
 * account, no session. A link of a draft competition works too (Test Cup #1 is one); a removed participant's link opens
 * nothing.
 *
 * The round the participant's page is about now, among the competition's rounds with online timing:
 * 1. a countdown or a running clock (the earliest start first - a running round before a scheduled one);
 * 2. else the next round not run yet, by schedule (`startsAt`) - a round whose organiser is late to start still counts;
 * 3. else the most recent one (all have ended).
 * The participant may not be entered in it (`entry` null).
 */
readonly final class OnlineAccess
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private CompetitionParticipantRoundRepository $competitionParticipantRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OnlineLinkNotFound
     */
    public function forToken(string $token): OnlineAccessContext
    {
        // Before the rows are read: the state's asOfMs must never be newer than what it holds
        $now = $this->clock->now();

        $participant = $this->competitionParticipantRepository->getByOnlineToken($token);

        if ($participant->isDeleted()) {
            throw new OnlineLinkNotFound();
        }

        $competition = $participant->competition;
        $onlineRound = self::currentOnlineRound($this->onlineRoundRepository->ofCompetition($competition->id->toString()), $now);
        $round = $onlineRound?->round;

        return new OnlineAccessContext(
            participant: $participant,
            competition: $competition,
            round: $round,
            onlineRound: $onlineRound,
            entry: $round === null
                ? null
                : $this->competitionParticipantRoundRepository->findOfParticipantInRound($participant->id->toString(), $round->id->toString()),
            clock: $round === null ? null : RoundClock::fromRound($round, $now),
            asOfMs: RoundClock::milliseconds($now),
        );
    }

    /**
     * @param list<OnlineRound> $onlineRounds by start (OnlineRoundRepository::ofCompetition())
     */
    private static function currentOnlineRound(array $onlineRounds, DateTimeImmutable $now): null|OnlineRound
    {
        $live = null;
        $liveStartedAtMs = PHP_INT_MAX;
        $next = null;
        $mostRecent = null;

        foreach ($onlineRounds as $onlineRound) {
            $clock = RoundClock::fromRound($onlineRound->round, $now);

            if ($clock->isLive()) {
                // The earliest start wins - a running round before a scheduled one; a tie keeps the schedule's order
                if ($live === null || ($clock->startedAtMs ?? PHP_INT_MAX) < $liveStartedAtMs) {
                    $live = $onlineRound;
                    $liveStartedAtMs = $clock->startedAtMs ?? PHP_INT_MAX;
                }
            } elseif ($clock->isWaiting()) {
                // The rounds come by start: the first one not run yet is the next one
                $next ??= $onlineRound;
            } else {
                // ... and the last one that ended is the most recent
                $mostRecent = $onlineRound;
            }
        }

        return $live ?? $next ?? $mostRecent;
    }
}
