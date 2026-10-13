<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One Finished tap of a participant's phone, as the review reads it (docs/features/online-competitions/README.md
 * §5.9, §5.10): its moment on the synced clock and when the server got it, whether the participant took it back within
 * the undo window, where it lies against the round (before the start, in the round, after the end - a finish after the
 * end does not count), and how late it arrived - with whether the phone was heard from meanwhile (OnlineReviewAssessment).
 */
readonly final class OnlineReviewTap implements \JsonSerializable
{
    public const string BEFORE_START = 'before_start';
    public const string IN_ROUND = 'in_round';
    public const string AFTER_END = 'after_end';

    /**
     * @param self::BEFORE_START|self::IN_ROUND|self::AFTER_END $position
     */
    public function __construct(
        public string $eventId,
        public int $momentMs,
        public int $receivedAtMs,
        public string $deviceId,
        public bool $undone,
        public string $position,
        // received − moment, never negative
        public int $lateMs,
        // Something else of the phone reached the server between the moment and this tap's arrival
        public bool $heardMeanwhile,
        // The official time this moment gives - null outside the round
        public null|int $seconds,
    ) {
    }

    public function counts(): bool
    {
        return $this->undone === false && $this->position === self::IN_ROUND;
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
            'undone' => $this->undone,
            'position' => $this->position,
            'lateMs' => $this->lateMs,
            'heardMeanwhile' => $this->heardMeanwhile,
            'seconds' => $this->seconds,
        ];
    }
}
