<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use SpeedPuzzling\Web\Message\StartSandboxDemoRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The demo round's stopwatch from its scheduled start (StartSandboxDemoRoundStopwatch): the entity's own Start at that
 * moment once it has passed, else its scheduled start (nothing is written when the moment comes - a scheduled start
 * counts as running from then on). Never on a stopwatch with a status - idempotent. In-person demo rounds only (no
 * online timing), no reveal: the demo's running round has no puzzle waiting for the stopwatch start.
 */
#[AsMessageHandler]
readonly final class StartSandboxDemoRoundStopwatchHandler
{
    public function __construct(
        private SandboxMode $sandboxMode,
        private CompetitionRoundRepository $competitionRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool true = started or scheduled now, false = left as it was
     *
     * @throws SandboxModeIsOff
     * @throws RoundStopwatchRefused
     */
    public function __invoke(StartSandboxDemoRoundStopwatch $message): bool
    {
        $this->sandboxMode->assertOn();

        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($round->stopwatchStatus !== null) {
            return false;
        }

        $now = $this->clock->now();
        // A whole second, as every stopwatch moment
        $startsAt = $message->startsAt->setTimestamp($message->startsAt->getTimestamp());

        if ($startsAt > $now) {
            $round->scheduleStopwatchStart($startsAt, $now);
        } else {
            $round->startStopwatch($startsAt);
        }

        return true;
    }
}
