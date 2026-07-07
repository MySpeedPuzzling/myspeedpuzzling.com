<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\UnpublishRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\RoundResultsPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UnpublishRoundResultsHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private RoundResultsPublisher $publisher,
    ) {
    }

    public function __invoke(UnpublishRoundResults $message): void
    {
        $round = $this->roundRepository->get($message->roundId);
        $round->unpublishResults();

        $this->publisher->publishPublicationChanged($round->id->toString(), false);
    }
}
