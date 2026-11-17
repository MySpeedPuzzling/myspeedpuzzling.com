<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\ScheduleRoundStart;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\RoundStopwatchReveal;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A start at a moment ahead - "Start in 10 s", "Start at 19:00:00" (CompetitionRound::scheduleStopwatchStart(), the
 * countdown every device shows by itself). The caller checked the rights (COMPETITION_EDIT); the acting player is not
 * stored. A round with online timing that ended is told to be reset first (RoundStopwatchRefusal::EndedUseReset).
 * Publishes nothing: the controller tells the open pages once the dispatch returned, after the commit
 * (RoundStopwatchLiveUpdates).
 *
 * The reveal on the stopwatch start (RoundStopwatchReveal): a reveal still ahead from an earlier countdown is withdrawn
 * first - always, ticked or not, so moving a countdown never reveals at its old moment - then "Reveal the puzzles"
 * (ScheduleRoundStart::$revealPuzzles) sets the new start's moment: hidden until the countdown ends.
 */
#[AsMessageHandler]
readonly final class ScheduleRoundStartHandler
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
    public function __invoke(ScheduleRoundStart $message): void
    {
        // First - it clears the entity manager when it locks
        $withReveal = $this->roundStopwatchReveal->lockIfNeeded($message->roundId, $message->revealPuzzles);
        // After the lock - a wait for it never leaves the action at a moment already past
        $now = $this->clock->now();
        $round = $this->competitionRoundRepository->get($message->roundId);

        // Every check before anything changes
        if ($withReveal && $message->revealPuzzles) {
            $this->roundStopwatchReveal->checkConfirmed($round, $now, $message->revealListHash);
        }

        $round->scheduleStopwatchStart(
            $message->startsAt,
            $now,
            onlineTiming: $this->onlineRoundRepository->ofRound($round->id->toString()) !== null,
        );

        if ($withReveal === false) {
            return;
        }

        $changed = $this->roundStopwatchReveal->withdrawFuture($round, $now);

        if ($message->revealPuzzles) {
            $changed += $this->roundStopwatchReveal->revealAt($round, $message->startsAt);
        }

        $this->roundStopwatchReveal->resync($changed);
    }
}
