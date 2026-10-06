<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\DeleteObsoletePuzzleImage;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
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
        private GetPuzzleRecord $getPuzzleRecord,
    ) {
    }

    public function __invoke(DeleteObsoletePuzzleImage $message): void
    {
        if ($this->getStoredFileReferences->referencedAmong([$message->path]) !== []) {
            // The rename did not commit after all - the puzzle still has this picture, nothing to do
            if ($this->getPuzzleRecord->byId($message->puzzleId)?->image === $message->path) {
                $this->logger->info('Old picture of a secret puzzle kept - the rename did not commit', [
                    'puzzle_id' => $message->puzzleId,
                    'path' => $message->path,
                ]);

                return;
            }

            // Still referenced elsewhere (a change request's snapshot of the puzzle, another puzzle): the guessable name
            // stays reachable - a person points that reference at the new name, then deletes the object (docs/TODO.md)
            $this->logger->warning('Old guessable picture of a secret puzzle kept - still referenced elsewhere', [
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
