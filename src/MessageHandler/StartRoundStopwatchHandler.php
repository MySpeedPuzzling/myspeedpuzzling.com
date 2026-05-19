<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class StartRoundStopwatchHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private HubInterface $hub,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(StartRoundStopwatch $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);
        $now = $this->clock->now();

        $round->startStopwatch($now);

        // Publish full state (same shape as RoundStopwatchStateController) so
        // subscribers can apply directly without a refetch round-trip. Read
        // startedAt back from the entity because resume semantics may have
        // shifted it forward by the pause duration.
        $this->hub->publish(new Update(
            '/round-stopwatch/' . $round->id->toString(),
            json_encode([
                'status' => 'running',
                'startedAt' => $round->stopwatchStartedAt?->format(\DateTimeInterface::ATOM),
                'stoppedAt' => null,
                'minutesLimit' => $round->minutesLimit,
            ], JSON_THROW_ON_ERROR),
            private: false,
        ));
    }
}
