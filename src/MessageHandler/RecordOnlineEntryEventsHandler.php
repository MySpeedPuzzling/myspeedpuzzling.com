<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineEntryEvent;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Exceptions\OnlineEntryMediaNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineParticipantNotEntered;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotOnline;
use SpeedPuzzling\Web\Message\RecordOnlineEntryEvents;
use SpeedPuzzling\Web\Message\VerifyOnlineMedia;
use SpeedPuzzling\Web\Query\GetLatestOnlineEntryEventMoment;
use SpeedPuzzling\Web\Query\GetOnlineEntryUsage;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryEventRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Results\RecordedOnlineEntryEvents;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineEntryResolver;
use SpeedPuzzling\Web\Value\OnlineEntryQuota;
use SpeedPuzzling\Web\Value\OnlineEventInput;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * A batch of a participant's phone's events (docs/features/online-competitions/README.md §5.6, contract §6):
 * - each event is read first (OnlineEventInput: `unknown_type`, `invalid_fields`); an id stored before is acknowledged
 *   again and nothing else happens (idempotent - the phone sends until it hears an ack);
 * - the rest are stored on the participant's online entry in the event's round - any round of the competition with
 *   online timing (late reports of a finished round still land on it) - created on the first contact; a round of
 *   another competition or without online timing is `wrong_round`, a round the participant is not entered in
 *   `not_entered`; a `media.uploaded` naming no media of this participant is `invalid_fields`; an entry holding
 *   OnlineEntryQuota::MAX_EVENTS_PER_ENTRY events takes no more (`too_many_events`);
 * - every stored event keeps the server's receive time - how late it came is the review's business;
 * - side effects, in the order the events happened on the phone's clock (not the order they came in):
 *   `device.ready` → the entry's ready moment, active device and clock quality - unless an older ready arrived late;
 *   `timeup.pieces` → the reported pieces and measure (the round's when none is named) - the latest report wins;
 *   `media.uploaded` → the media's upload moment and manifest, then VerifyOnlineMedia after the commit.
 *
 * Two requests storing the same new event at the same time: the later commit fails on the primary key, the controller
 * answers a retryable error, and the phone is acknowledged when it sends again. Nothing of the participants lock: the
 * entry's own rows only.
 */
#[AsMessageHandler]
readonly final class RecordOnlineEntryEventsHandler
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private OnlineEntryResolver $onlineEntryResolver,
        private OnlineEntryEventRepository $onlineEntryEventRepository,
        private OnlineEntryMediaRepository $onlineEntryMediaRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private GetLatestOnlineEntryEventMoment $getLatestOnlineEntryEventMoment,
        private GetOnlineEntryUsage $getOnlineEntryUsage,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordOnlineEntryEvents $message): RecordedOnlineEntryEvents
    {
        $participant = $this->competitionParticipantRepository->get($message->participantId);
        $receivedAtMs = RoundClock::milliseconds($this->clock->now());

        $acks = [];
        $refused = [];
        /** @var array<string, OnlineEventInput> $inputs lower-case id => the event, the first copy of an id */
        $inputs = [];

        foreach ($message->events as $event) {
            $input = OnlineEventInput::read($event, $message->roundId);

            if (is_array($input)) {
                $refused[] = $input;
            } elseif (isset($inputs[$input->id]) === false) {
                $inputs[$input->id] = $input;
            }
        }

        $storedBefore = array_flip($this->onlineEntryEventRepository->existingIds(array_keys($inputs)));
        $new = [];

        foreach ($inputs as $id => $input) {
            if (isset($storedBefore[$id])) {
                $acks[] = $id;
            } else {
                $new[] = $input;
            }
        }

        // The phone's order of things, whatever order they arrived in
        usort($new, static fn (OnlineEventInput $a, OnlineEventInput $b): int => $a->clientMs <=> $b->clientMs);

        /** @var array<string, OnlineEntry|string> $entries round id => the online entry, or why its events are refused */
        $entries = [];
        /** @var array<string, null|int> $latestTimeUpMs online entry id => the latest time-up report's moment */
        $latestTimeUpMs = [];
        /** @var array<string, int> $eventCounts online entry id => its events, stored before and now */
        $eventCounts = [];
        $activeDeviceChanged = false;

        foreach ($new as $input) {
            $onlineEntry = $entries[$input->roundId] ??= $this->onlineEntryOf($participant, $input->roundId);

            if (is_string($onlineEntry)) {
                $refused[] = ['id' => $input->id, 'reason' => $onlineEntry];

                continue;
            }

            $entryId = $onlineEntry->id->toString();
            $eventCounts[$entryId] ??= $this->getOnlineEntryUsage->eventCount($entryId);

            if ($eventCounts[$entryId] >= OnlineEntryQuota::MAX_EVENTS_PER_ENTRY) {
                $refused[] = ['id' => $input->id, 'reason' => 'too_many_events'];

                continue;
            }

            $media = null;

            if ($input->type === OnlineEventType::MediaUploaded) {
                $media = $this->participantsMedia($participant, $input->mediaId());

                if ($media === null) {
                    $refused[] = ['id' => $input->id, 'reason' => OnlineEventInput::INVALID_FIELDS];

                    continue;
                }
            }

            $this->onlineEntryEventRepository->save(new OnlineEntryEvent(
                id: Uuid::fromString($input->id),
                onlineEntry: $onlineEntry,
                deviceId: $message->deviceId,
                type: $input->type,
                clientMs: $input->clientMs,
                perfMs: $input->perfMs,
                offsetMs: $input->offsetMs,
                rttMs: $input->rttMs,
                receivedAtMs: $receivedAtMs,
                payload: $input->data,
            ));
            $acks[] = $input->id;
            $eventCounts[$entryId]++;

            if ($input->type === OnlineEventType::DeviceReady && $this->applyReady($onlineEntry, $input, $message->deviceId)) {
                $activeDeviceChanged = true;
            }

            if ($input->type === OnlineEventType::TimeUpPieces) {
                if (array_key_exists($entryId, $latestTimeUpMs) === false) {
                    $latestTimeUpMs[$entryId] = $this->getLatestOnlineEntryEventMoment->of($entryId, OnlineEventType::TimeUpPieces);
                }

                if ($latestTimeUpMs[$entryId] === null || $input->clientMs >= $latestTimeUpMs[$entryId]) {
                    $latestTimeUpMs[$entryId] = $input->clientMs;
                    $this->applyTimeUp($onlineEntry, $input);
                }
            }

            if ($media !== null) {
                $media->markUploaded($input->clientMs, $input->manifest());
                // After the commit: the HEAD must find the row as stored
                $this->messageBus->dispatch(new VerifyOnlineMedia($media->id->toString()), [new DispatchAfterCurrentBusStamp()]);
            }
        }

        return new RecordedOnlineEntryEvents(
            acks: $acks,
            refused: $refused,
            activeDeviceChanged: $activeDeviceChanged,
        );
    }

    /**
     * @return OnlineEntry|string the entry, or the reason to refuse the round's events
     */
    private function onlineEntryOf(CompetitionParticipant $participant, string $roundId): OnlineEntry|string
    {
        try {
            return $this->onlineEntryResolver->resolve($participant, $roundId);
        } catch (OnlineRoundNotOnline) {
            return 'wrong_round';
        } catch (OnlineParticipantNotEntered) {
            return 'not_entered';
        }
    }

    private function participantsMedia(CompetitionParticipant $participant, null|string $mediaId): null|OnlineEntryMedia
    {
        if ($mediaId === null) {
            return null;
        }

        try {
            $media = $this->onlineEntryMediaRepository->get($mediaId);
        } catch (OnlineEntryMediaNotFound) {
            return null;
        }

        return $media->onlineEntry->entry->participant->id->equals($participant->id) ? $media : null;
    }

    /**
     * The device that said it is ready holds the entry - the latest ready on the phones' clock wins
     *
     * @return bool whether another device holds it now
     */
    private function applyReady(OnlineEntry $onlineEntry, OnlineEventInput $input, string $deviceId): bool
    {
        $readyAt = new DateTimeImmutable('@' . intdiv($input->clientMs, 1000));

        if ($onlineEntry->readyAt !== null && $readyAt < $onlineEntry->readyAt) {
            return false;
        }

        $changed = $onlineEntry->activeDeviceId !== $deviceId;

        $onlineEntry->readyAt = $readyAt;
        $onlineEntry->activeDeviceId = $deviceId;
        $onlineEntry->clockOffsetMs = $input->offsetMs;
        $onlineEntry->clockRttMs = $input->rttMs;

        return $changed;
    }

    private function applyTimeUp(OnlineEntry $onlineEntry, OnlineEventInput $input): void
    {
        $onlineEntry->reportedPieces = $input->pieces();
        $onlineEntry->reportedMeasure = $input->measure()
            ?? $this->onlineRoundRepository->ofRound($onlineEntry->entry->round->id->toString())?->unfinishedMeasure;
    }
}
