<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\OnlineMomentVerdictRefused;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\UnmarkOnlineMomentGap;
use SpeedPuzzling\Web\Repository\OnlineReviewMomentVerdictRepository;
use SpeedPuzzling\Web\Results\OnlineMomentGapChange;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReviewMoments;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Not a gap" (docs/features/online-competitions/README.md §5.9): the moment is checked as for a mark
 * (OnlineReviewMoments), then its verdict is removed - a moment without one stays as it was. Nothing else changes.
 */
#[AsMessageHandler]
readonly final class UnmarkOnlineMomentGapHandler
{
    public function __construct(
        private OnlineReviewMoments $onlineReviewMoments,
        private OnlineReviewMomentVerdictRepository $verdictRepository,
    ) {
    }

    /**
     * @throws OnlineRoundNotFound
     * @throws OnlineMomentVerdictRefused nothing was written
     */
    public function __invoke(UnmarkOnlineMomentGap $message): OnlineMomentGapChange
    {
        $event = $this->onlineReviewMoments->moment($message->competitionId, $message->roundId, $message->participantId, $message->eventId);
        $onlineEntryId = $event->onlineEntry->id->toString();
        $verdict = $this->verdictRepository->ofEvent($event->id->toString());

        if ($verdict === null) {
            return new OnlineMomentGapChange(changed: false, onlineEntryId: $onlineEntryId);
        }

        $this->verdictRepository->delete($verdict);

        return new OnlineMomentGapChange(changed: true, onlineEntryId: $onlineEntryId);
    }
}
