<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\OnlineEntryMediaNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineMediaObjectMissing;
use SpeedPuzzling\Web\Message\VerifyOnlineMedia;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineStoreBreaker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * After a phone reported an evidence object uploaded (`media.uploaded`): is it really in its store, and how big
 * (OnlineEntryMedia::$verifiedBytes) - the review uses only what was checked (README §5.11). Async (config/packages/
 * messenger.php): a HEAD request to object storage never holds up the phone's request.
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
            $bytes = $this->onlineMediaStorage->head($media);
        } catch (\Throwable $e) {
            $this->onlineStoreBreaker->recordFailure($media->store, OnlineStoreBreaker::SERVER);

            throw $e;
        }

        if ($bytes === null) {
            throw new OnlineMediaObjectMissing($message->mediaId);
        }

        $media->markVerified($bytes);
    }
}
