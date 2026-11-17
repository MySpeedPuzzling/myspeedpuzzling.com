<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Message\RenameCompetitionDisplay;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RenameCompetitionDisplayHandler
{
    public function __construct(
        private CompetitionDisplayRepository $displayRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RenameCompetitionDisplay $message): void
    {
        $this->displayRepository->get($message->displayId)
            ->rename(CompetitionDisplay::cleanName($message->name), $this->clock->now());
    }
}
