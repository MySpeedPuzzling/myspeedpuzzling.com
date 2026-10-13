<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Exceptions\OnlineEntryAlreadyConfirmed;
use SpeedPuzzling\Web\Exceptions\OnlineMediaPendingLimitReached;
use SpeedPuzzling\Web\Exceptions\OnlineMediaQuotaExceeded;
use SpeedPuzzling\Web\Exceptions\OnlineParticipantNotEntered;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotOnline;
use SpeedPuzzling\Web\Message\RegisterOnlineMedia;
use SpeedPuzzling\Web\Query\GetOnlineEntryUsage;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineEntryResolver;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineStoreBreaker;
use SpeedPuzzling\Web\Value\OnlineEntryQuota;
use SpeedPuzzling\Web\Value\OnlineReviewStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An evidence object a participant's phone is about to upload (docs/features/online-competitions/README.md §5.11):
 * its row under the id the controller made, on the participant's online entry in the round (created on the first
 * contact), in the store the circuit breaker picks, under the key
 * `online/{competitionId}/{roundId}/{participantId}/{mediaId}.jpg` (a burst: `.bin`). Answers the media (HandledStamp)
 * - the controller presigns its upload once this is committed. Each presign makes a new row: one whose URL ran out
 * simply never gets uploaded.
 *
 * Refused (the link is a possibly hostile client): an entry whose result the review confirmed (its evidence is
 * closed), an entry at its cap of objects or bytes (OnlineEntryQuota), and - for a while - an entry with too many
 * uploads presigned lately but never verified.
 */
#[AsMessageHandler]
readonly final class RegisterOnlineMediaHandler
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private OnlineEntryResolver $onlineEntryResolver,
        private OnlineEntryMediaRepository $onlineEntryMediaRepository,
        private GetOnlineEntryUsage $getOnlineEntryUsage,
        private OnlineStoreBreaker $onlineStoreBreaker,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OnlineRoundNotOnline
     * @throws OnlineParticipantNotEntered
     * @throws OnlineEntryAlreadyConfirmed
     * @throws OnlineMediaQuotaExceeded
     * @throws OnlineMediaPendingLimitReached
     */
    public function __invoke(RegisterOnlineMedia $message): OnlineEntryMedia
    {
        $participant = $this->competitionParticipantRepository->get($message->participantId);
        $onlineEntry = $this->onlineEntryResolver->resolve($participant, $message->roundId);
        $now = $this->clock->now();

        if ($onlineEntry->reviewStatus === OnlineReviewStatus::Confirmed) {
            throw new OnlineEntryAlreadyConfirmed();
        }

        $usage = $this->getOnlineEntryUsage->media(
            $onlineEntry->id->toString(),
            $now->modify(sprintf('-%d seconds', OnlineEntryQuota::PENDING_MEDIA_WINDOW_SECONDS)),
        );

        if (
            $usage['media'] >= OnlineEntryQuota::MAX_MEDIA_PER_ENTRY
            || $usage['bytes'] + $message->bytes > OnlineEntryQuota::MAX_MEDIA_BYTES_PER_ENTRY
        ) {
            throw new OnlineMediaQuotaExceeded();
        }

        if ($usage['pending'] >= OnlineEntryQuota::MAX_PENDING_MEDIA_PER_ENTRY) {
            throw new OnlineMediaPendingLimitReached();
        }

        $mediaId = Uuid::fromString($message->mediaId);

        $objectKey = sprintf(
            'online/%s/%s/%s/%s.%s',
            $participant->competition->id->toString(),
            $onlineEntry->entry->round->id->toString(),
            $participant->id->toString(),
            $mediaId->toString(),
            $message->contentType === 'image/jpeg' ? 'jpg' : 'bin',
        );

        $media = new OnlineEntryMedia(
            id: $mediaId,
            onlineEntry: $onlineEntry,
            kind: $message->kind,
            takenMs: $message->takenMs,
            store: $this->onlineStoreBreaker->storeForNewUpload(),
            objectKey: $objectKey,
            contentType: $message->contentType,
            declaredBytes: $message->bytes,
            createdAt: $now,
            relatedEventId: $message->relatedEventId === null ? null : Uuid::fromString($message->relatedEventId),
        );

        $this->onlineEntryMediaRepository->save($media);

        return $media;
    }
}
