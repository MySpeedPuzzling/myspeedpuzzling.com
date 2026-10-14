<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Something the review should look at in a participant's online entry (docs/features/online-competitions/README.md §7,
 * OnlineReviewAssessment): a code (online_rounds.review.flag.<code>), how many times, and for how many seconds in all
 * where that means something. Codes stored on the entry by other parts (OnlineEntry::$flags) come as they are.
 */
readonly final class OnlineReviewFlag implements \JsonSerializable
{
    // A Finished tap arrived long after its moment while other things of the phone kept arriving
    public const string ARRIVED_LATE = 'arrived_late';
    public const string CAMERA_LOST = 'camera_lost';
    public const string PAGE_HIDDEN = 'page_hidden';
    public const string OFFLINE = 'offline';
    // The page was (re)opened while the round ran on a device that was ready (stream RP, `page.reloaded`)
    public const string PAGE_RELOADED = 'page_reloaded';
    // More than one device sent for the entry
    public const string TAKEOVER = 'takeover';
    public const string SEVERAL_TAPS = 'several_taps';
    public const string TAP_AFTER_END = 'tap_after_end';

    public function __construct(
        public string $code,
        public null|int $count = null,
        public null|int $seconds = null,
    ) {
    }

    /**
     * @return array{code: string, count: null|int, seconds: null|int}
     */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'count' => $this->count,
            'seconds' => $this->seconds,
        ];
    }
}
