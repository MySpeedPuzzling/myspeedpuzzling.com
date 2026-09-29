<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\DeleteOrphanedAvatar;
use SpeedPuzzling\Web\Services\Storage\OrphanedAvatarFinder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteOrphanedAvatarHandler
{
    public function __construct(
        private Filesystem $filesystem,
        private OrphanedAvatarFinder $orphanedAvatarFinder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws FilesystemException
     */
    public function __invoke(DeleteOrphanedAvatar $message): void
    {
        // The listing may be minutes old by now - never delete what became referenced meanwhile
        if ($this->orphanedAvatarFinder->isOrphan($message->path) === false) {
            $this->logger->info('Avatar kept - it is referenced (or not an avatar) after all', [
                'path' => $message->path,
            ]);

            return;
        }

        $this->filesystem->delete($message->path);

        $this->logger->info('Orphaned avatar deleted', [
            'path' => $message->path,
        ]);
    }
}
