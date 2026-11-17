<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteCompetitionDisplayImages;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayImages;
use SpeedPuzzling\Web\Services\DeleteUnreferencedStoredFiles;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only display uploads are ever deleted here, and only those no row references any more (another display - a
 * duplicate - may still show them). A storage problem never fails this handler.
 */
#[AsMessageHandler]
readonly final class DeleteCompetitionDisplayImagesHandler
{
    public function __construct(
        private DeleteUnreferencedStoredFiles $deleteUnreferencedStoredFiles,
    ) {
    }

    public function __invoke(DeleteCompetitionDisplayImages $message): void
    {
        ($this->deleteUnreferencedStoredFiles)(array_values(array_filter($message->paths, DisplayImages::isDisplayUpload(...))));
    }
}
