<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Exceptions\OnlineEntryAlreadyConfirmed;
use SpeedPuzzling\Web\Exceptions\OnlineEntryMediaNotFound;
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
 * simply never gets uploaded (MarkAbandonedOnlineMediaHandler marks it later).
 *
 * A presign after a failed upload names the media it replaces (`supersededMediaId` = the phone's
 * `previousFailure.mediaId` - it dropped that URL): one of this participant's that was never reported uploaded is marked
 * abandoned now (OnlineEntryMedia::markAbandoned()) - the review says "never arrived", and it no longer counts as
 * pending. Anything else named there (another participant's, an uploaded one, an unknown id) is left alone.
 *
 * Refused (the link is a possibly hostile client): an entry whose result the review confirmed (its evidence is
 * closed), an entry at its cap of objects or bytes (OnlineEntryQuota), and - for a while - an entry with too many
 * uploads presigned lately that the phone never reported done (uploads waiting for the async check do not count, nor do
 * abandoned ones). Every check comes before any change - a refusal rolls back, but leaves nothing changed in the unit of
 * work either.
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

        $superseded = $this->supersededMedia($participant, $message->supersededMediaId);
        $pendingSince = $now->modify(sprintf('-%d seconds', OnlineEntryQuota::PENDING_MEDIA_WINDOW_SECONDS));
        $usage = $this->getOnlineEntryUsage->media($onlineEntry->id->toString(), $pendingSince);

        if (
            $usage['media'] >= OnlineEntryQuota::MAX_MEDIA_PER_ENTRY
            || $usage['bytes'] + $message->bytes > OnlineEntryQuota::MAX_MEDIA_BYTES_PER_ENTRY
        ) {
            throw new OnlineMediaQuotaExceeded();
        }

        // The upload this one replaces is pending no more
        $pending = $usage['pending'] - (self::countsAsPending($superseded, $onlineEntry->id->toString(), $pendingSince) ? 1 : 0);

        if ($pending >= OnlineEntryQuota::MAX_PENDING_MEDIA_PER_ENTRY) {
            throw new OnlineMediaPendingLimitReached();
        }

        $superseded?->markAbandoned($now);

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

    /**
     * The participant's own media the phone gave up - not reported uploaded, not marked before
     */
    private function supersededMedia(CompetitionParticipant $participant, null|string $mediaId): null|OnlineEntryMedia
    {
        if ($mediaId === null) {
            return null;
        }

        try {
            $media = $this->onlineEntryMediaRepository->get($mediaId);
        } catch (OnlineEntryMediaNotFound) {
            return null;
        }

        if (
            $media->onlineEntry->entry->participant->id->equals($participant->id) === false
            || $media->uploadedAtMs !== null
            || $media->abandonedAt !== null
        ) {
            return null;
        }

        return $media;
    }

    /**
     * Counted by GetOnlineEntryUsage::media() as pending: of this entry, not uploaded, not abandoned, made in the window
     */
    private static function countsAsPending(null|OnlineEntryMedia $media, string $onlineEntryId, DateTimeImmutable $pendingSince): bool
    {
        return $media !== null
            && $media->onlineEntry->id->toString() === $onlineEntryId
            && $media->createdAt > $pendingSince;
    }
}
