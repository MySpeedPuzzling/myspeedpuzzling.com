<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\OnlineReviewFlag;
use SpeedPuzzling\Web\Results\OnlineReviewSuggestion;
use SpeedPuzzling\Web\Results\OnlineReviewTap;

/**
 * What the review reads from one participant's online entry (docs/features/online-competitions/README.md §5.9, §5.10,
 * §7) - pure, from the round's clock and the entry's events (GetOnlineReviewData):
 *
 * - **Taps**: every `finish.button`; one named by a `finish.button.undone` (`{eventId}`) is undone. A tap before the
 *   start or after the end (the stop or the time limit, whichever came first) does not count.
 * - **Suggestion** (OnlineReviewSuggestion): the last tap that counts - the moment the participant's own page shows as
 *   their finish (compete_flow.js currentFinish()) - else the pieces reported at time-up, else none.
 * - **Status**: `confirmed` once a reviewer confirmed it; otherwise by the suggestion: `needs_look` (a finish to
 *   confirm), `unfinished` (pieces to confirm), `no_finish`.
 * - **Flags** (OnlineReviewFlag, README §7): a tap that arrived LATE_ARRIVAL_MS or more after its moment while other
 *   things of the phone reached the server meanwhile (the heartbeats live only in the presence, so the phone's other
 *   events are the evidence that it was online - GetOnlineReviewData's `heard_meanwhile`); camera lost, page hidden and
 *   offline - how often and how long while the round ran (per device, a loss without its return lasts until the end);
 *   more than one device; more than one tap that counts; taps after the end; and whatever the entry stores itself.
 * - **Adjustment**: a confirmed moment against the tap nearest to it - confirmed − tap, the reviewer's correction (the
 *   accuracy report). Phase 2 adds the detection error the same way, against the finish candidates.
 *
 * @phpstan-import-type OnlineReviewEventRow from \SpeedPuzzling\Web\Query\GetOnlineReviewData
 */
final readonly class OnlineReviewAssessment
{
    public const int LATE_ARRIVAL_MS = 30_000;
    // How much of the late window does not count as "heard from" - an event sent in the same moment as the tap
    public const int HEARD_GRACE_MS = 5_000;

    public const string STATUS_NEEDS_LOOK = 'needs_look';
    public const string STATUS_CONFIRMED = 'confirmed';
    public const string STATUS_UNFINISHED = 'unfinished';
    public const string STATUS_NO_FINISH = 'no_finish';

    /**
     * @param list<OnlineReviewTap> $taps by moment
     * @param self::STATUS_* $status
     * @param list<OnlineReviewFlag> $flags
     */
    private function __construct(
        public array $taps,
        public OnlineReviewSuggestion $suggestion,
        public string $status,
        public array $flags,
        // The tap nearest to the confirmed moment, and confirmed − its moment
        public null|OnlineReviewTap $adjustedTap,
        public null|int $adjustmentMs,
    ) {
    }

    /**
     * @param list<OnlineReviewEventRow> $events the entry's events (any order)
     * @param list<string> $storedFlags OnlineEntry::$flags
     */
    public static function of(
        RoundClock $clock,
        int $nowMs,
        bool $confirmed,
        null|int $confirmedMomentMs,
        null|string $reportedMeasure,
        null|int $reportedPieces,
        null|int $piecesTotal,
        array $events,
        array $storedFlags = [],
    ): self {
        usort($events, static fn (array $a, array $b): int => [$a['client_ms'], $a['id']] <=> [$b['client_ms'], $b['id']]);

        $taps = self::taps($clock, $events);
        $counting = array_values(array_filter($taps, static fn (OnlineReviewTap $tap): bool => $tap->counts()));
        $lastCounting = $counting === [] ? null : $counting[count($counting) - 1];

        if ($lastCounting !== null && $lastCounting->seconds !== null) {
            $suggestion = OnlineReviewSuggestion::finished($lastCounting, $lastCounting->seconds);
        } elseif ($reportedPieces !== null) {
            $suggestion = OnlineReviewSuggestion::unfinished(self::piecesPlaced($reportedMeasure, $reportedPieces, $piecesTotal), $reportedMeasure, $reportedPieces);
        } else {
            $suggestion = OnlineReviewSuggestion::none();
        }

        $status = match (true) {
            $confirmed => self::STATUS_CONFIRMED,
            $suggestion->kind === OnlineReviewSuggestion::FINISHED => self::STATUS_NEEDS_LOOK,
            $suggestion->kind === OnlineReviewSuggestion::UNFINISHED => self::STATUS_UNFINISHED,
            default => self::STATUS_NO_FINISH,
        };

        $adjustedTap = null;

        if ($confirmedMomentMs !== null) {
            foreach ($taps as $tap) {
                if ($adjustedTap === null || abs($tap->momentMs - $confirmedMomentMs) < abs($adjustedTap->momentMs - $confirmedMomentMs)) {
                    $adjustedTap = $tap;
                }
            }
        }

        return new self(
            taps: $taps,
            suggestion: $suggestion,
            status: $status,
            flags: self::flags($clock, $nowMs, $events, $taps, $counting, $storedFlags),
            adjustedTap: $adjustedTap,
            adjustmentMs: $adjustedTap === null || $confirmedMomentMs === null ? null : $confirmedMomentMs - $adjustedTap->momentMs,
        );
    }

    /**
     * Pieces placed from a time-up report: as reported in "placed", the puzzle's pieces minus the report in "left" -
     * null when the measure or the piece count is not known
     */
    public static function piecesPlaced(null|string $measure, int $pieces, null|int $piecesTotal): null|int
    {
        return match ($measure) {
            OnlineUnfinishedMeasure::PiecesPlaced->value => $pieces,
            OnlineUnfinishedMeasure::PiecesLeft->value => $piecesTotal === null ? null : max(0, $piecesTotal - $pieces),
            default => null,
        };
    }

    /**
     * @return array{0: null|int, 1: null|int} the moments a moment must lie between to count: the start, and the end
     *                                         once the round ended (null while it runs - nothing can lie after now)
     */
    public static function window(RoundClock $clock): array
    {
        if ($clock->startedAtMs === null || $clock->status === RoundClock::WAITING || $clock->status === RoundClock::SCHEDULED) {
            return [null, null];
        }

        return [$clock->startedAtMs, $clock->hasEnded() ? $clock->endsAtMs : null];
    }

    /**
     * @param list<OnlineReviewEventRow> $events by moment
     * @return list<OnlineReviewTap>
     */
    private static function taps(RoundClock $clock, array $events): array
    {
        [$startMs, $endMs] = self::window($clock);
        $undone = [];

        foreach ($events as $event) {
            $tapId = $event['payload']['eventId'] ?? null;

            if ($event['type'] === OnlineEventType::FinishButtonUndone->value && is_string($tapId)) {
                $undone[strtolower($tapId)] = true;
            }
        }

        $taps = [];

        foreach ($events as $event) {
            if ($event['type'] !== OnlineEventType::FinishButton->value) {
                continue;
            }

            $momentMs = $event['client_ms'];
            $position = match (true) {
                $startMs === null || $momentMs < $startMs => OnlineReviewTap::BEFORE_START,
                $endMs !== null && $momentMs > $endMs => OnlineReviewTap::AFTER_END,
                default => OnlineReviewTap::IN_ROUND,
            };

            $taps[] = new OnlineReviewTap(
                eventId: $event['id'],
                momentMs: $momentMs,
                receivedAtMs: $event['received_at_ms'],
                deviceId: $event['device_id'],
                undone: isset($undone[strtolower($event['id'])]),
                position: $position,
                lateMs: max(0, $event['received_at_ms'] - $momentMs),
                heardMeanwhile: $event['heard_meanwhile'],
                seconds: $position === OnlineReviewTap::IN_ROUND ? intdiv($momentMs - $startMs, 1000) : null,
            );
        }

        return $taps;
    }

    /**
     * @param list<OnlineReviewEventRow> $events by moment
     * @param list<OnlineReviewTap> $taps
     * @param list<OnlineReviewTap> $counting
     * @param list<string> $storedFlags
     * @return list<OnlineReviewFlag>
     */
    private static function flags(RoundClock $clock, int $nowMs, array $events, array $taps, array $counting, array $storedFlags): array
    {
        $flags = [];

        $late = array_filter(
            $taps,
            static fn (OnlineReviewTap $tap): bool => $tap->undone === false && $tap->heardMeanwhile && $tap->lateMs >= self::LATE_ARRIVAL_MS,
        );

        if ($late !== []) {
            $flags[] = new OnlineReviewFlag(
                OnlineReviewFlag::ARRIVED_LATE,
                count: count($late),
                seconds: intdiv(max(array_map(static fn (OnlineReviewTap $tap): int => $tap->lateMs, $late)), 1000),
            );
        }

        [$startMs, $endMs] = self::window($clock);

        if ($startMs !== null) {
            $until = $endMs ?? max($startMs, $nowMs);
            $outages = [
                OnlineReviewFlag::CAMERA_LOST => [OnlineEventType::CameraLost, OnlineEventType::CameraBack],
                OnlineReviewFlag::PAGE_HIDDEN => [OnlineEventType::PageHidden, OnlineEventType::PageVisible],
                OnlineReviewFlag::OFFLINE => [OnlineEventType::NetworkOffline, OnlineEventType::NetworkOnline],
            ];

            foreach ($outages as $code => [$down, $up]) {
                $outage = self::outage($events, $down, $up, $startMs, $until);

                if ($outage !== null) {
                    $flags[] = new OnlineReviewFlag($code, count: $outage[0], seconds: intdiv($outage[1], 1000));
                }
            }
        }

        $devices = array_unique(array_column($events, 'device_id'));

        if (count($devices) > 1) {
            $flags[] = new OnlineReviewFlag(OnlineReviewFlag::TAKEOVER, count: count($devices));
        }

        if (count($counting) > 1) {
            $flags[] = new OnlineReviewFlag(OnlineReviewFlag::SEVERAL_TAPS, count: count($counting));
        }

        $afterEnd = count(array_filter($taps, static fn (OnlineReviewTap $tap): bool => $tap->undone === false && $tap->position === OnlineReviewTap::AFTER_END));

        if ($afterEnd > 0) {
            $flags[] = new OnlineReviewFlag(OnlineReviewFlag::TAP_AFTER_END, count: $afterEnd);
        }

        $codes = array_map(static fn (OnlineReviewFlag $flag): string => $flag->code, $flags);

        foreach ($storedFlags as $stored) {
            if (in_array($stored, $codes, true) === false) {
                $flags[] = new OnlineReviewFlag($stored);
                $codes[] = $stored;
            }
        }

        return $flags;
    }

    /**
     * How often and how long (ms) something was down while the round ran: per device, from a `down` event to the next
     * `up` one - a down without its up lasts until the end of the window. Null when it never was.
     *
     * @param list<OnlineReviewEventRow> $events by moment
     * @return null|array{0: int, 1: int} count, milliseconds
     */
    private static function outage(array $events, OnlineEventType $down, OnlineEventType $up, int $fromMs, int $untilMs): null|array
    {
        /** @var array<string, int> $openSince device => when it went down */
        $openSince = [];
        $intervals = [];

        foreach ($events as $event) {
            $device = $event['device_id'];

            if ($event['type'] === $down->value) {
                $openSince[$device] ??= $event['client_ms'];
            } elseif ($event['type'] === $up->value && isset($openSince[$device])) {
                $intervals[] = [$openSince[$device], $event['client_ms']];
                unset($openSince[$device]);
            }
        }

        foreach ($openSince as $since) {
            $intervals[] = [$since, max($since, $untilMs)];
        }

        $count = 0;
        $milliseconds = 0;

        foreach ($intervals as [$since, $until]) {
            $clippedSince = max($since, $fromMs);
            $clippedUntil = min($until, $untilMs);

            if ($clippedSince <= $clippedUntil) {
                $count++;
                $milliseconds += $clippedUntil - $clippedSince;
            }
        }

        return $count === 0 ? null : [$count, $milliseconds];
    }
}
