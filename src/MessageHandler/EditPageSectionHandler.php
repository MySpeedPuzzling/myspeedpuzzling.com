<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\EditPageSection;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Services\PageSectionContentSanitizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditPageSectionHandler
{
    public function __construct(
        private CompetitionPageSectionRepository $sectionRepository,
        private PageSectionContentSanitizer $sanitizer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EditPageSection $message): void
    {
        $section = $this->sectionRepository->get($message->sectionId);

        $section->edit(
            title: $message->title !== null && trim($message->title) !== '' ? trim($message->title) : null,
            content: $this->sanitizer->sanitize($section->type, $message->content),
            updatedAt: $this->clock->now(),
        );
    }
}
