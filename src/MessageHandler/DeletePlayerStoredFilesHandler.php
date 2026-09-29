<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use League\Flysystem\StorageAttributes;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\DeletePlayerStoredFiles;
use SpeedPuzzling\Web\Query\GetStoredFileReferences;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A storage problem never fails this handler: the account is already gone, so
 * every failure is logged at warning and the rest carries on. Deletes go through
 * FailoverS3Adapter, which also drops a payload still waiting in the upload spool
 * (so it cannot be uploaded later) and queues a delete S3 refused for retry.
 * A missing object counts as deleted.
 */
#[AsMessageHandler]
readonly final class DeletePlayerStoredFilesHandler
{
    public function __construct(
        private Filesystem $filesystem,
        private GetStoredFileReferences $getStoredFileReferences,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeletePlayerStoredFiles $message): void
    {
        // Only ever dispatched after the deletion committed - a player row here means
        // the message came from somewhere else, and their files are still in use
        if ($this->getStoredFileReferences->playerExists($message->playerId)) {
            $this->logger->warning('Stored files of a player were not deleted - the player still exists', [
                'player_id' => $message->playerId,
            ]);

            return;
        }

        $candidates = $message->paths;

        foreach ($this->listPlayerDirectory($message->playerId) as $path) {
            $candidates[] = $path;
        }

        $candidates = array_values(array_unique($candidates));

        if ($candidates === []) {
            return;
        }

        // Pair/team times handed over to another member keep their photo - it lives on under this prefix
        $referenced = $this->getStoredFileReferences->referencedAmong($candidates);

        foreach ($candidates as $path) {
            if (isset($referenced[$path])) {
                continue;
            }

            try {
                $this->filesystem->delete($path);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not delete a stored file of a deleted player', [
                    'player_id' => $message->playerId,
                    'path' => $path,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Finished-puzzle photos and result share images live under `players/<id>/`.
     *
     * @return list<string>
     */
    private function listPlayerDirectory(string $playerId): array
    {
        $paths = [];

        try {
            /** @var StorageAttributes $item */
            foreach ($this->filesystem->listContents("players/$playerId", true) as $item) {
                if ($item->isFile()) {
                    $paths[] = $item->path();
                }
            }
        } catch (\Throwable $e) {
            // AsyncAws leaks its own exceptions besides FilesystemException, hence \Throwable
            $this->logger->warning('Could not list the stored files of a deleted player', [
                'player_id' => $playerId,
                'exception' => $e,
            ]);
        }

        return $paths;
    }
}
