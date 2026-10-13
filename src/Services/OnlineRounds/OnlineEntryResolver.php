<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Exceptions\OnlineParticipantNotEntered;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotOnline;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;

/**
 * The online entry a participant's phone writes to (RecordOnlineEntryEvents, RegisterOnlineMedia): the participant's
 * entry in the round the phone named - any round of their competition that uses online timing, not only the current
 * one, so what a phone reports late (a time-up count, an end photo) still lands on the round it belongs to after the
 * next round became the current one. Created on the phone's first contact with the round (only persisted - the
 * handler's transaction writes it).
 */
readonly final class OnlineEntryResolver
{
    public function __construct(
        private OnlineRoundRepository $onlineRoundRepository,
        private CompetitionParticipantRoundRepository $competitionParticipantRoundRepository,
        private OnlineEntryRepository $onlineEntryRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OnlineRoundNotOnline the round does not use online timing, or it is another competition's
     * @throws OnlineParticipantNotEntered
     */
    public function resolve(CompetitionParticipant $participant, string $roundId): OnlineEntry
    {
        $onlineRound = $this->onlineRoundRepository->ofRound($roundId);

        if ($onlineRound === null || $onlineRound->round->competition->id->equals($participant->competition->id) === false) {
            throw new OnlineRoundNotOnline();
        }

        $entry = $this->competitionParticipantRoundRepository->findOfParticipantInRound(
            $participant->id->toString(),
            $onlineRound->round->id->toString(),
        ) ?? throw new OnlineParticipantNotEntered();

        $onlineEntry = $this->onlineEntryRepository->ofEntry($entry->id->toString());

        if ($onlineEntry === null) {
            $onlineEntry = new OnlineEntry(
                id: Uuid::uuid7(),
                entry: $entry,
                firstSeenAt: $this->clock->now(),
            );
            $this->onlineEntryRepository->save($onlineEntry);
        }

        return $onlineEntry;
    }
}
