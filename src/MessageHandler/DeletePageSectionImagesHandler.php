<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\DeletePageSectionImages;
use SpeedPuzzling\Web\Query\GetStoredFileReferences;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A storage problem never fails this handler: the section change is committed already, a stray object is only logged.
 */
#[AsMessageHandler]
readonly final class DeletePageSectionImagesHandler
{
    public function __construct(
        private Filesystem $filesystem,
        private GetStoredFileReferences $getStoredFileReferences,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeletePageSectionImages $message): void
    {
        // A picture still shown somewhere (the change did not commit after all, or another section of the page uses the
        // same upload) stays
        $referenced = $this->getStoredFileReferences->referencedAmong($message->paths);

        foreach ($message->paths as $path) {
            if (isset($referenced[$path])) {
                continue;
            }

            try {
                $this->filesystem->delete($path);
            } catch (FilesystemException $exception) {
                $this->logger->warning('A page section picture could not be deleted', [
                    'path' => $path,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
