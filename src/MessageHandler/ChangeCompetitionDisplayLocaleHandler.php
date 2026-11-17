<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Message\ChangeCompetitionDisplayLocale;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ChangeCompetitionDisplayLocaleHandler
{
    public function __construct(
        private CompetitionDisplayRepository $displayRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ChangeCompetitionDisplayLocale $message): void
    {
        $this->displayRepository->get($message->displayId)
            ->changeLocale(CompetitionDisplay::checkLocale($message->locale), $this->clock->now());
    }
}
