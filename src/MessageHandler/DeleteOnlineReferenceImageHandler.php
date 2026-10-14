<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\DeleteOnlineReferenceImage;
use SpeedPuzzling\Web\Query\GetStoredFileReferences;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReferenceImages;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A storage problem never fails this handler: the change is committed already, and an object left behind is deleted
 * by the orphan sweep of `online/` once no round holds it (myspeedpuzzling:prune-online-media) - only logged here.
 */
#[AsMessageHandler]
readonly final class DeleteOnlineReferenceImageHandler
{
    public function __construct(
        private OnlineReferenceImages $referenceImages,
        private GetStoredFileReferences $getStoredFileReferences,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteOnlineReferenceImage $message): void
    {
        // Never anything but a reference image; one a round still holds (the change did not commit after all) stays
        if (OnlineReferenceImages::isObjectKey($message->objectKey) === false || $this->getStoredFileReferences->referencedAmong([$message->objectKey]) !== []) {
            return;
        }

        try {
            $this->referenceImages->delete($message->objectKey);
        } catch (FilesystemException $exception) {
            $this->logger->info('A replaced reference image of an online round could not be deleted - the orphan sweep takes it', [
                'key' => $message->objectKey,
                'exception' => $exception,
            ]);
        }
    }
}
