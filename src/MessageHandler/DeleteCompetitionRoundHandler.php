<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    public function __invoke(DeleteCompetitionRound $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);
        $this->competitionRoundRepository->delete($round);
    }
}
