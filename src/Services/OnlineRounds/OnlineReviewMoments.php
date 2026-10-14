<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Entity\OnlineEntryEvent;
use SpeedPuzzling\Web\Exceptions\OnlineEntryEventNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineMomentVerdictRefused;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Repository\OnlineEntryEventRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineEventType;

/**
 * The finish moments a reviewer may give a verdict on (docs/features/online-competitions/README.md §5.9, the G key):
 * the moments the review shows them - every Finished tap (`finish.button`) of the participant in the round, and every
 * raise of the hands (`finish.candidate`) unless the round's detection only watches (`shadow`, stream P2F: the reviewer
 * never sees a candidate there - one asked for is answered as unknown, so nothing tells a candidate exists).
 */
readonly final class OnlineReviewMoments
{
    public function __construct(
        private OnlineRoundRepository $onlineRoundRepository,
        private OnlineEntryEventRepository $onlineEntryEventRepository,
    ) {
    }

    /**
     * The moment's event - of this participant, in this round of the authorised competition
     *
     * @throws OnlineRoundNotFound the round does not use online timing, or it is another event's
     * @throws OnlineMomentVerdictRefused `moment_not_found`
     */
    public function moment(string $competitionId, string $roundId, string $participantId, string $eventId): OnlineEntryEvent
    {
        $onlineRound = $this->onlineRoundRepository->ofRound($roundId);

        if ($onlineRound === null || $onlineRound->round->competition->id->toString() !== strtolower($competitionId)) {
            throw new OnlineRoundNotFound();
        }

        try {
            $event = $this->onlineEntryEventRepository->get(strtolower($eventId));
        } catch (OnlineEntryEventNotFound) {
            throw OnlineMomentVerdictRefused::because(OnlineMomentVerdictRefused::MOMENT_NOT_FOUND);
        }

        $entry = $event->onlineEntry->entry;
        $ofTheParticipant = $entry->round->id->equals($onlineRound->round->id)
            && $entry->participant->id->toString() === strtolower($participantId);
        $shown = $event->type === OnlineEventType::FinishButton
            || ($event->type === OnlineEventType::FinishCandidate && $onlineRound->detectionMode !== OnlineDetectionMode::Shadow);

        if ($ofTheParticipant === false || $shown === false) {
            throw OnlineMomentVerdictRefused::because(OnlineMomentVerdictRefused::MOMENT_NOT_FOUND);
        }

        return $event;
    }
}
