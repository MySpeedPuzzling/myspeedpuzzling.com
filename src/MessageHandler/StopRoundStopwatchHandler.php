<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\RoundStopwatchReveal;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stops the round's stopwatch - the end of an online round ("End now", stored rounded up to the next whole second:
 * CompetitionRound::endStopwatch()), a pause in person (CompetitionRound::stopStopwatch()). Publishes nothing: the
 * controller tells the open pages once the dispatch returned, after the commit (RoundStopwatchLiveUpdates). A countdown
 * stopped before its moment never ran: a reveal it was started with is taken back (RoundStopwatchReveal).
 */
#[AsMessageHandler]
readonly final class StopRoundStopwatchHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private ClockInterface $clock,
        private RoundStopwatchReveal $roundStopwatchReveal,
    ) {
    }

    public function __invoke(StopRoundStopwatch $message): void
    {
        // First - it clears the entity manager when it locks
        $withReveal = $this->roundStopwatchReveal->lockIfNeeded($message->roundId, false);
        // After the lock - a wait for it never leaves the action at a moment already past
        $now = $this->clock->now();
        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($this->onlineRoundRepository->ofRound($round->id->toString()) !== null) {
            $round->endStopwatch($now);
        } else {
            $round->stopStopwatch($now);
        }

        if ($withReveal) {
            $this->roundStopwatchReveal->resync($this->roundStopwatchReveal->withdrawFuture($round, $now));
        }
    }
}
