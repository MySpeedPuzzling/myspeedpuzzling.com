<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class StopRoundStopwatchHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private HubInterface $hub,
    ) {
    }

    public function __invoke(StopRoundStopwatch $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $round->stopStopwatch();

        $this->hub->publish(new Update(
            '/round-stopwatch/' . $round->id->toString(),
            json_encode([
                'action' => 'stop',
            ], JSON_THROW_ON_ERROR),
            private: false,
        ));
    }
}
