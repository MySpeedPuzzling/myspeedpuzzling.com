<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Exceptions\OnlineEntryMediaNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineMediaObjectMissing;
use SpeedPuzzling\Web\Message\VerifyOnlineMedia;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineStoreBreaker;
use SpeedPuzzling\Web\Value\OnlineEntryQuota;
use SpeedPuzzling\Web\Value\OnlineMediaObject;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * After a phone reported an evidence object uploaded (`media.uploaded`): is it really in its store, and is it what was
 * declared - the review uses only what was checked (README §5.11). Async (config/packages/messenger.php): a HEAD
 * request to object storage never holds up the phone's request.
 * - As declared (the size, the content type, within OnlineEntryQuota::MAX_MEDIA_BYTES) → verifiedBytes.
 * - Anything else is deleted from the store and stays unverified, with a warning: the presigned URL is signed for the
 *   declared type and length, so only a store that does not check signed headers lets it through.
 * - A missing object fails the message (OnlineMediaObjectMissing), so the async transport tries again later.
 * - A store that does not answer counts as the server's failure for the circuit breaker, and is tried again too.
 * - A media deleted meanwhile (pruned, its participant removed with the round) has nothing left to check.
 */
#[AsMessageHandler]
readonly final class VerifyOnlineMediaHandler
{
    public function __construct(
        private OnlineEntryMediaRepository $onlineEntryMediaRepository,
        private OnlineMediaStorage $onlineMediaStorage,
        private OnlineStoreBreaker $onlineStoreBreaker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OnlineMediaObjectMissing
     */
    public function __invoke(VerifyOnlineMedia $message): void
    {
        try {
            $media = $this->onlineEntryMediaRepository->get($message->mediaId);
        } catch (OnlineEntryMediaNotFound) {
            return;
        }

        try {
            $object = $this->onlineMediaStorage->inspect($media);
        } catch (\Throwable $e) {
            $this->onlineStoreBreaker->recordFailure($media->store, OnlineStoreBreaker::SERVER);

            throw $e;
        }

        if ($object === null) {
            throw new OnlineMediaObjectMissing($message->mediaId);
        }

        $problem = self::problem($media, $object);

        if ($problem === null) {
            $media->markVerified($object->bytes);

            return;
        }

        try {
            $this->onlineMediaStorage->delete($media);
        } catch (\Throwable $e) {
            $this->logger->warning('Deleting an online evidence object that is not as declared failed', [
                'media_id' => $media->id->toString(),
                'exception' => $e,
            ]);
        }

        $this->logger->warning('An online evidence object was not as declared - deleted, left unverified', [
            'media_id' => $media->id->toString(),
            'problem' => $problem,
            'declared_bytes' => $media->declaredBytes,
            'stored_bytes' => $object->bytes,
            'declared_type' => $media->contentType,
            'stored_type' => $object->contentType,
        ]);
    }

    private static function problem(OnlineEntryMedia $media, OnlineMediaObject $object): null|string
    {
        if ($object->bytes > OnlineEntryQuota::MAX_MEDIA_BYTES) {
            return 'too_large';
        }

        if ($object->bytes !== $media->declaredBytes) {
            return 'size';
        }

        // `image/jpeg; charset=…` is still image/jpeg
        $storedType = $object->contentType === null ? null : strtolower(trim(explode(';', $object->contentType)[0]));

        if ($storedType !== null && $storedType !== strtolower($media->contentType)) {
            return 'content_type';
        }

        return null;
    }
}
