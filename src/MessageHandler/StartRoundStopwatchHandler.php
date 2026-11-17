<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\RoundStopwatchReveal;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts the round's stopwatch now, or resumes a stopped one (CompetitionRound::startStopwatch()) - in person only: a
 * round with online timing that ended is refused (RoundStopwatchRefusal::EndedUseReset, a Reset runs it again).
 * Publishes nothing: the controller tells the open pages once the dispatch returned, after the commit
 * (RoundStopwatchLiveUpdates).
 *
 * "Reveal the puzzles" ticked (StartRoundStopwatch::$revealPuzzles): the round's puzzles waiting for the stopwatch
 * start come out at this moment (the resume moment for a paused clock) - only for the list the organiser confirmed,
 * never for a start that changes nothing (already running). Unticked: nothing is revealed (RoundStopwatchReveal).
 */
#[AsMessageHandler]
readonly final class StartRoundStopwatchHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private ClockInterface $clock,
        private RoundStopwatchReveal $roundStopwatchReveal,
    ) {
    }

    /**
     * @throws RoundStopwatchRefused
     */
    public function __invoke(StartRoundStopwatch $message): void
    {
        // First - it clears the entity manager when it locks
        $withReveal = $this->roundStopwatchReveal->lockIfNeeded($message->roundId, $message->revealPuzzles);
        // After the lock - a wait for it never leaves the action at a moment already past
        $now = $this->clock->now();
        $round = $this->competitionRoundRepository->get($message->roundId);
        $onlineTiming = $this->onlineRoundRepository->ofRound($round->id->toString()) !== null;

        if ($withReveal === false) {
            $round->startStopwatch($now, onlineTiming: $onlineTiming);

            return;
        }

        // A second "Start" from a stale page changes nothing - and reveals nothing either
        if ($round->stopwatchStatusAt($now) === RoundStopwatchStatus::RUNNING) {
            return;
        }

        // Every check before anything changes
        if ($message->revealPuzzles) {
            $this->roundStopwatchReveal->checkConfirmed($round, $now, $message->revealListHash);
        }

        $round->startStopwatch($now, onlineTiming: $onlineTiming);

        $changed = $this->roundStopwatchReveal->withdrawFuture($round, $now);

        if ($message->revealPuzzles) {
            // On the whole second the stopwatch keeps
            $changed += $this->roundStopwatchReveal->revealAt($round, $now->setTimestamp($now->getTimestamp()));
        }

        $this->roundStopwatchReveal->resync($changed);
    }
}
