<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ApproveCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ApproveCompetition $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);
        $approvedBy = $this->playerRepository->get($message->approvedByPlayerId);

        $competition->approve($approvedBy, $this->clock->now());
    }
}
