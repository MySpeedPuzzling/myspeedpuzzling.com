<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineReviewMomentVerdict;
use SpeedPuzzling\Web\Exceptions\OnlineMomentVerdictRefused;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\MarkOnlineMomentGap;
use SpeedPuzzling\Web\Repository\OnlineReviewMomentVerdictRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\OnlineMomentGapChange;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReviewMoments;
use SpeedPuzzling\Web\Value\OnlineMomentVerdict;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The reviewer's G stored (docs/features/online-competitions/README.md §5.9): the moment must be one the review shows
 * them - a Finished tap or a raise of the hands of this participant in this round, never a raise in a `shadow` round
 * (OnlineReviewMoments) -, then its verdict `gap` is kept with the reviewer and the time. A moment marked already
 * stays as it was (the first mark, nothing written). Nothing else changes - not the suggestion, a confirmation or an
 * official time.
 */
#[AsMessageHandler]
readonly final class MarkOnlineMomentGapHandler
{
    public function __construct(
        private OnlineReviewMoments $onlineReviewMoments,
        private OnlineReviewMomentVerdictRepository $verdictRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OnlineRoundNotFound
     * @throws OnlineMomentVerdictRefused nothing was written
     */
    public function __invoke(MarkOnlineMomentGap $message): OnlineMomentGapChange
    {
        $event = $this->onlineReviewMoments->moment($message->competitionId, $message->roundId, $message->participantId, $message->eventId);
        $onlineEntryId = $event->onlineEntry->id->toString();

        if ($this->verdictRepository->ofEvent($event->id->toString()) !== null) {
            return new OnlineMomentGapChange(changed: false, onlineEntryId: $onlineEntryId);
        }

        $this->verdictRepository->save(new OnlineReviewMomentVerdict(
            id: Uuid::uuid7(),
            onlineEntry: $event->onlineEntry,
            event: $event,
            verdict: OnlineMomentVerdict::Gap,
            markedBy: $this->playerRepository->get($message->actingPlayerId),
            markedAt: $this->clock->now(),
        ));

        return new OnlineMomentGapChange(changed: true, onlineEntryId: $onlineEntryId);
    }
}
