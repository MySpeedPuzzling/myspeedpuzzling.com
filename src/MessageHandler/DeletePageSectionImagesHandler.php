<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeletePageSectionImages;
use SpeedPuzzling\Web\Services\DeleteUnreferencedStoredFiles;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A picture still shown somewhere (the change did not commit after all, or another section of the page uses the same
 * upload) stays - DeleteUnreferencedStoredFiles. A storage problem never fails this handler.
 */
#[AsMessageHandler]
readonly final class DeletePageSectionImagesHandler
{
    public function __construct(
        private DeleteUnreferencedStoredFiles $deleteUnreferencedStoredFiles,
    ) {
    }

    public function __invoke(DeletePageSectionImages $message): void
    {
        ($this->deleteUnreferencedStoredFiles)($message->paths);
    }
}
