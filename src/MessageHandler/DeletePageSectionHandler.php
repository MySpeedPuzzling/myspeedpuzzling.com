<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeletePageSection;
use SpeedPuzzling\Web\Message\DeletePageSectionImages;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Services\PageSectionContentSanitizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * The section's pictures are deleted once the deletion has committed. The other sections keep their positions - the
 * order is relative, a gap changes nothing.
 */
#[AsMessageHandler]
readonly final class DeletePageSectionHandler
{
    public function __construct(
        private CompetitionPageSectionRepository $sectionRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(DeletePageSection $message): void
    {
        $section = $this->sectionRepository->get($message->sectionId);
        $images = PageSectionContentSanitizer::imagePaths($section->content);

        $this->sectionRepository->delete($section);

        if ($images !== []) {
            $this->messageBus->dispatch(new DeletePageSectionImages($images), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
