<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\DeleteObsoletePuzzleImage;
use SpeedPuzzling\Web\Query\GetStoredFileReferences;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A storage problem never fails this handler: the rename is committed already, a stray object is only logged.
 */
#[AsMessageHandler]
readonly final class DeleteObsoletePuzzleImageHandler
{
    public function __construct(
        private Filesystem $filesystem,
        private GetStoredFileReferences $getStoredFileReferences,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteObsoletePuzzleImage $message): void
    {
        // Still in use (another puzzle, a change request's snapshot) - or the rename did not commit after all
        if ($this->getStoredFileReferences->referencedAmong([$message->path]) !== []) {
            $this->logger->info('Old picture of a secret puzzle kept - still referenced', [
                'puzzle_id' => $message->puzzleId,
                'path' => $message->path,
            ]);

            return;
        }

        try {
            $this->filesystem->delete($message->path);
        } catch (FilesystemException $exception) {
            $this->logger->warning('Old picture of a secret puzzle could not be deleted', [
                'puzzle_id' => $message->puzzleId,
                'path' => $message->path,
                'exception' => $exception,
            ]);
        }
    }
}
