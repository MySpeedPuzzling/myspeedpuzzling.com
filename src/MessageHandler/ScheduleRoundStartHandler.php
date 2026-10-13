<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\ScheduleRoundStart;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A start at a moment ahead - "Start in 10 s", "Start at 19:00:00" (CompetitionRound::scheduleStopwatchStart(), the
 * countdown every device shows by itself). The caller checked the rights (COMPETITION_EDIT); the acting player is not
 * stored. Publishes nothing: the controller tells the open pages once the dispatch returned, after the commit
 * (RoundStopwatchLiveUpdates).
 */
#[AsMessageHandler]
readonly final class ScheduleRoundStartHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RoundStopwatchRefused
     */
    public function __invoke(ScheduleRoundStart $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $round->scheduleStopwatchStart($message->startsAt, $this->clock->now());
    }
}
