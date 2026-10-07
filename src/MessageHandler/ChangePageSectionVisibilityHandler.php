<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\ChangePageSectionVisibility;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ChangePageSectionVisibilityHandler
{
    public function __construct(
        private CompetitionPageSectionRepository $sectionRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ChangePageSectionVisibility $message): void
    {
        $this->sectionRepository
            ->get($message->sectionId)
            ->changeVisibility($message->visible, $this->clock->now());
    }
}
