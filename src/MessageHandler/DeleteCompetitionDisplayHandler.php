<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteCompetitionDisplay;
use SpeedPuzzling\Web\Message\DeleteCompetitionDisplayImages;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Its link stops working at once. The design's uploaded images are deleted once the deletion committed - only those no
 * other display (a duplicate shares its source's files) references (DeleteCompetitionDisplayImages).
 */
#[AsMessageHandler]
readonly final class DeleteCompetitionDisplayHandler
{
    public function __construct(
        private CompetitionDisplayRepository $displayRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(DeleteCompetitionDisplay $message): void
    {
        $display = $this->displayRepository->get($message->displayId);
        $images = DisplayDesignSanitizer::imagePaths($display->design);

        $this->displayRepository->delete($display);

        if ($images !== []) {
            $this->messageBus->dispatch(new DeleteCompetitionDisplayImages($images), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
