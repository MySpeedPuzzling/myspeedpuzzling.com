<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Exceptions\OnlineParticipantNotEntered;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotOnline;
use SpeedPuzzling\Web\Message\RegisterOnlineMedia;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineEntryResolver;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineStoreBreaker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An evidence object a participant's phone is about to upload (docs/features/online-competitions/README.md §5.11):
 * its row under the id the controller made, on the participant's online entry in the round (created on the first
 * contact), in the store the circuit breaker picks, under the key
 * `online/{competitionId}/{roundId}/{participantId}/{mediaId}.jpg` (a burst: `.bin`). Answers the media (HandledStamp)
 * - the controller presigns its upload once this is committed. Each presign makes a new row: one whose URL ran out
 * simply never gets uploaded.
 */
#[AsMessageHandler]
readonly final class RegisterOnlineMediaHandler
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private OnlineEntryResolver $onlineEntryResolver,
        private OnlineEntryMediaRepository $onlineEntryMediaRepository,
        private OnlineStoreBreaker $onlineStoreBreaker,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OnlineRoundNotOnline
     * @throws OnlineParticipantNotEntered
     */
    public function __invoke(RegisterOnlineMedia $message): OnlineEntryMedia
    {
        $participant = $this->competitionParticipantRepository->get($message->participantId);
        $onlineEntry = $this->onlineEntryResolver->resolve($participant, $message->roundId);
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
            createdAt: $this->clock->now(),
            relatedEventId: $message->relatedEventId === null ? null : Uuid::fromString($message->relatedEventId),
        );

        $this->onlineEntryMediaRepository->save($media);

        return $media;
    }
}
