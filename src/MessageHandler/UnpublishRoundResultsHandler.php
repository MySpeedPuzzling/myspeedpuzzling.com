<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\UnpublishRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UnpublishRoundResultsHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function __invoke(UnpublishRoundResults $message): void
    {
        $round = $this->roundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $round->unpublishResults();
    }
}
