<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class PublishRoundResultsHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function __invoke(PublishRoundResults $message): void
    {
        $round = $this->roundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $round->publishResults($this->clock->now());
    }
}
