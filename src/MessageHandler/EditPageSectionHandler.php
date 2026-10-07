<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\DeletePageSectionImages;
use SpeedPuzzling\Web\Message\EditPageSection;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Services\PageSectionContentSanitizer;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Pictures the section no longer shows (a removed gallery photo, a replaced sponsor logo) are deleted once the edit has
 * committed.
 */
#[AsMessageHandler]
readonly final class EditPageSectionHandler
{
    public function __construct(
        private CompetitionPageSectionRepository $sectionRepository,
        private PageSectionContentSanitizer $sanitizer,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(EditPageSection $message): void
    {
        $section = $this->sectionRepository->get($message->sectionId);
        $imagesBefore = PageSectionContentSanitizer::imagePaths($section->content);
        $content = $this->sanitizer->sanitize($section->type, $message->content, PageSectionOwner::of($section));

        $section->edit(
            title: AddPageSectionHandler::cleanTitle($message->title),
            content: $content,
            updatedAt: $this->clock->now(),
        );

        $removedImages = array_values(array_diff($imagesBefore, PageSectionContentSanitizer::imagePaths($content)));

        if ($removedImages !== []) {
            $this->messageBus->dispatch(new DeletePageSectionImages($removedImages), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
