<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One participant of the control room's grid (`online_live`, docs/features/online-competitions/implementation-plan.md
 * WP 1.7): who they are, whether their phone made contact and said it is ready, the last heartbeat of the device
 * holding their entry (`presence` - null when none is known), another device open on the same link (`otherDevice`),
 * the last Finished tap not undone, the latest finish candidate of the run (Phase 2 - `candidate`, with how many the run
 * has), what they reported at time-up, the review, the latest periodic photo with a short-lived URL. Times are epoch
 * milliseconds on the server's clock.
 *
 * @phpstan-type Presence array{lastSeenMs: null|int, deviceId: null|string, visible: null|bool, camera: null|array{ok: null|bool, w: null|int, h: null|int, fps: null|float}, clock: null|array{offsetMs: null|float, rttMs: null|float}, outbox: null|array{events: null|int, media: null|int}, detector: null|array{ok: null|bool, fps: null|float, mode: null|string, state: null|string, inferenceMs: null|float, view: null|string}}
 */
readonly final class OnlineLiveParticipant implements \JsonSerializable
{
    /**
     * @param null|Presence $presence
     * @param list<string> $flags
     * @param null|array{deviceId: null|string, lastSeenMs: null|int} $otherDevice
     */
    public function __construct(
        public string $participantId,
        public string $entryId,
        public string $name,
        public null|string $country,
        // The phone made contact (an online entry exists), or a heartbeat is known
        public bool $seen,
        public null|int $readyAtMs,
        public null|string $activeDeviceId,
        // The round trip of the clock sample the phone was ready with
        public null|float $readyClockRttMs,
        public null|array $presence,
        public null|string $finishEventId,
        public null|int $finishMomentMs,
        public null|int $finishReceivedAtMs,
        public null|string $reportedMeasure,
        public null|int $reportedPieces,
        public string $review,
        public array $flags,
        public null|string $photoMediaId,
        public null|int $photoTakenMs,
        public null|string $photoUrl,
        // Another device beat on the same link (kept apart from the holder's presence) - its id and when it was seen
        public null|array $otherDevice = null,
        public null|string $candidateEventId = null,
        public null|int $candidateMomentMs = null,
        public null|int $candidateReceivedAtMs = null,
        public int $candidates = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'participantId' => $this->participantId,
            'entryId' => $this->entryId,
            'name' => $this->name,
            'country' => $this->country,
            'seen' => $this->seen,
            'readyAtMs' => $this->readyAtMs,
            'activeDeviceId' => $this->activeDeviceId,
            'readyClockRttMs' => $this->readyClockRttMs,
            'presence' => $this->presence,
            'otherDevice' => $this->otherDevice,
            'finish' => $this->finishMomentMs === null ? null : [
                'eventId' => $this->finishEventId,
                'momentMs' => $this->finishMomentMs,
                'receivedAtMs' => $this->finishReceivedAtMs,
            ],
            'candidate' => $this->candidateMomentMs === null ? null : [
                'eventId' => $this->candidateEventId,
                'momentMs' => $this->candidateMomentMs,
                'receivedAtMs' => $this->candidateReceivedAtMs,
                'count' => $this->candidates,
            ],
            'reported' => $this->reportedPieces === null ? null : [
                'measure' => $this->reportedMeasure,
                'pieces' => $this->reportedPieces,
            ],
            'review' => $this->review,
            'flags' => $this->flags,
            'photo' => $this->photoUrl === null ? null : [
                'mediaId' => $this->photoMediaId,
                'takenMs' => $this->photoTakenMs,
                'url' => $this->photoUrl,
            ],
        ];
    }
}
