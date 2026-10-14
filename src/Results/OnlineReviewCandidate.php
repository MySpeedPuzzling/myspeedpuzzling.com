<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One finish candidate of a participant's phone - raised hands its detector held for half a second (`finish.candidate`,
 * docs/features/online-competitions/README.md §5.7, §5.9) -, as the review reads it: its moment (the first raised
 * frame's capture time on the synced clock) and when the server got it, where it lies against the round (before the
 * start, in the round, after the end - like a Finished tap; a candidate has no undo), how late it arrived, and what the
 * detector said about it (the payload the page sends: when the hold was complete, how long the hands stayed up, the
 * signal's score, the camera view, the round's detection mode then and the finish machine's version). Every detector
 * value is null when the phone did not send it.
 */
readonly final class OnlineReviewCandidate implements \JsonSerializable
{
    /**
     * @param OnlineReviewTap::BEFORE_START|OnlineReviewTap::IN_ROUND|OnlineReviewTap::AFTER_END $position
     */
    public function __construct(
        public string $eventId,
        public int $momentMs,
        public int $receivedAtMs,
        public string $deviceId,
        public string $position,
        // received − moment, never negative
        public int $lateMs,
        // Something else of the phone reached the server between the moment and this candidate's arrival
        public bool $heardMeanwhile,
        // The official time this moment gives - null outside the round
        public null|int $seconds,
        public null|int $detectedAtMs = null,
        public null|int $heldMs = null,
        public null|float $score = null,
        public null|string $view = null,
        public null|string $mode = null,
        public null|int $machineVersion = null,
    ) {
    }

    /**
     * Inside the round - a candidate is never taken back
     */
    public function counts(): bool
    {
        return $this->position === OnlineReviewTap::IN_ROUND;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'eventId' => $this->eventId,
            'momentMs' => $this->momentMs,
            'receivedAtMs' => $this->receivedAtMs,
            'deviceId' => $this->deviceId,
            'position' => $this->position,
            'lateMs' => $this->lateMs,
            'heardMeanwhile' => $this->heardMeanwhile,
            'seconds' => $this->seconds,
            'detectedAtMs' => $this->detectedAtMs,
            'heldMs' => $this->heldMs,
            'score' => $this->score,
            'view' => $this->view,
            'mode' => $this->mode,
            'machineVersion' => $this->machineVersion,
        ];
    }
}
