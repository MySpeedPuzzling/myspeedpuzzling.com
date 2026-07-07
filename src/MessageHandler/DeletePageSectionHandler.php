<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeletePageSection;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeletePageSectionHandler
{
    public function __construct(
        private CompetitionPageSectionRepository $sectionRepository,
    ) {
    }

    public function __invoke(DeletePageSection $message): void
    {
        $section = $this->sectionRepository->get($message->sectionId);
        $this->sectionRepository->delete($section);
    }
}
