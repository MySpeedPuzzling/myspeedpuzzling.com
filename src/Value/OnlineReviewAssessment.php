<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\OnlineReviewCandidate;
use SpeedPuzzling\Web\Results\OnlineReviewFlag;
use SpeedPuzzling\Web\Results\OnlineReviewSuggestion;
use SpeedPuzzling\Web\Results\OnlineReviewTap;

/**
 * What the review reads from one participant's online entry (docs/features/online-competitions/README.md §5.9, §5.10,
 * §7) - pure, from the round's clock and the entry's events (GetOnlineReviewData). Everything counts for the round's
 * current run only - a run is one start of the round clock, its events are those from the start on; an earlier run's
 * (before a Reset) stay stored and listed, but never count:
 *
 * - **Taps**: every `finish.button`; one named by a `finish.button.undone` (`{eventId}`) is undone. A tap before the
 *   start (an earlier run's - Finished works only while a round runs) or after the end (the stop or the time limit,
 *   whichever came first) does not count.
 * - **Candidates** (Phase 2, OnlineReviewCandidate): every `finish.candidate` - raised hands the phone's detector held;
 *   one counts inside the round (a candidate has no undo), placed against the round like a tap.
 * - **Suggestion** (OnlineReviewSuggestion): in a round whose detection is `on`, the earliest of the first candidate that
 *   counts and the first tap that counts (README §5.9: the official finish is the first candidate whose photo shows a
 *   complete puzzle - the reviewer looks at it and moves on with `G` when it shows a gap; stream P2F: a candidate after
 *   the participant's own tap is never suggested over the tap); otherwise - and in `shadow` / `off` always - the last
 *   tap that counts - the moment the participant's own page shows as their finish (compete_flow.js currentFinish()) -
 *   else the pieces reported at time-up, else none.
 * - **Status**: `confirmed` once a reviewer confirmed it; otherwise by the suggestion: `needs_look` (a finish to
 *   confirm), `unfinished` (pieces to confirm), `no_finish`.
 * - **Flags** (OnlineReviewFlag, README §7): a tap of the run that arrived LATE_ARRIVAL_MS or more after its moment
 *   while other things of the phone reached the server meanwhile (the heartbeats live only in the presence, so the
 *   phone's other events are the evidence that it was online - GetOnlineReviewData's `heard_meanwhile`); camera lost,
 *   page hidden and offline - how often and how long while the round ran (per device; a loss that began before the
 *   start counts from it once its return came in the run, a loss without its return lasts until the end when it began
 *   in the run); more than one device in the run (the one holding the entry at the start - the last ready before it -
 *   and every device that sent anything since); more than one tap that counts; taps after the end; and whatever the
 *   entry stores itself.
 * - **Adjustment**: a confirmed moment against the tap of the run nearest to it - confirmed − tap, the reviewer's
 *   correction (the accuracy report).
 * - **Detection** (Phase 2, the accuracy report - README §5.9): the detected candidate is the counting candidate
 *   nearest to the confirmed moment within MATCH_WINDOW_MS - before a confirmation the suggested one, else the one
 *   nearest to the reference tap, else the first; the **detection error** = confirmed − detected; the **delta** = the
 *   reference tap (the adjusted one, else the last tap that counts) − the counting candidate nearest to it within the
 *   window. With detection on (`shadow` / `on`): **missed raises** = counting taps without a counting candidate within
 *   the window, on a device whose detector watched the round then; **detector not running** (stream P2F) = such taps
 *   on a device whose detector did not (detectorRunningAt(): the device's latest `device.ready` - its `detector`, none
 *   on a page loaded while the detection was off - or `detector.changed` before the tap; unsupported, broken,
 *   skipped, a failed test, stopped as too slow) - not the detector's miss; **false raises** = counting candidates that
 *   are not the finish (the confirmed one; before a confirmation the suggested one), lie outside the window of every
 *   counting tap and - once confirmed - outside the window of the confirmed moment (a candidate that close is that
 *   finish's, wherever the reviewer moved the moment).
 *
 * @phpstan-import-type OnlineReviewEventRow from \SpeedPuzzling\Web\Query\GetOnlineReviewData
 */
final readonly class OnlineReviewAssessment
{
    public const int LATE_ARRIVAL_MS = 30_000;
    // A tap and a candidate (or a confirmed moment and a candidate) this close are the same finish
    public const int MATCH_WINDOW_MS = 10_000;
    // The camera views the phone's detector reports (assets/online/hands_up_signal.js)
    public const array VIEWS = ['angled', 'topDown'];
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
     * @param list<OnlineReviewCandidate> $candidates by moment
     */
    private function __construct(
        public array $taps,
        public OnlineReviewSuggestion $suggestion,
        public string $status,
        public array $flags,
        // The tap nearest to the confirmed moment, and confirmed − its moment
        public null|OnlineReviewTap $adjustedTap,
        public null|int $adjustmentMs,
        public array $candidates = [],
        // The tap the review stands on: the adjusted one, else the last tap that counts
        public null|OnlineReviewTap $referenceTap = null,
        // The candidate the finish was detected by, and confirmed − its moment (null before a confirmation)
        public null|OnlineReviewCandidate $detectedCandidate = null,
        public null|int $detectionErrorMs = null,
        // The reference tap − the counting candidate nearest to it within MATCH_WINDOW_MS
        public null|OnlineReviewCandidate $deltaCandidate = null,
        public null|int $deltaMs = null,
        // Null while the round's detection is off
        public null|int $missedRaises = null,
        public null|int $falseRaises = null,
        // Counting taps without a candidate on a device whose detector did not watch the round - null while it is off
        public null|int $detectorNotRunning = null,
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
        OnlineDetectionMode $detection = OnlineDetectionMode::Off,
    ): self {
        usort($events, static fn (array $a, array $b): int => [$a['client_ms'], $a['id']] <=> [$b['client_ms'], $b['id']]);

        $taps = self::taps($clock, $events);
        $counting = array_values(array_filter($taps, static fn (OnlineReviewTap $tap): bool => $tap->counts()));
        $lastCounting = $counting === [] ? null : $counting[count($counting) - 1];
        $candidates = self::candidates($clock, $events);
        $countingCandidates = array_values(array_filter($candidates, static fn (OnlineReviewCandidate $candidate): bool => $candidate->counts()));
        $firstCandidate = $countingCandidates[0] ?? null;
        $firstCounting = $counting[0] ?? null;
        // A candidate after the participant's own tap never comes before the tap
        $tapFirst = $firstCounting !== null && $firstCandidate !== null && $firstCounting->momentMs < $firstCandidate->momentMs;

        if ($detection === OnlineDetectionMode::On && $firstCandidate !== null && $firstCandidate->seconds !== null && $tapFirst === false) {
            $suggestion = OnlineReviewSuggestion::detected($firstCandidate, $firstCandidate->seconds);
        } elseif ($detection === OnlineDetectionMode::On && $tapFirst && $firstCounting->seconds !== null) {
            $suggestion = OnlineReviewSuggestion::finished($firstCounting, $firstCounting->seconds);
        } elseif ($lastCounting !== null && $lastCounting->seconds !== null) {
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
        [$startMs] = self::window($clock);
        // The run's own taps - an earlier run's lie before its start
        $runTaps = $startMs === null ? [] : array_values(array_filter($taps, static fn (OnlineReviewTap $tap): bool => $tap->momentMs >= $startMs));

        if ($confirmedMomentMs !== null) {
            foreach ($runTaps as $tap) {
                if ($adjustedTap === null || abs($tap->momentMs - $confirmedMomentMs) < abs($adjustedTap->momentMs - $confirmedMomentMs)) {
                    $adjustedTap = $tap;
                }
            }
        }

        $referenceTap = $adjustedTap ?? $lastCounting;
        $confirmedMoment = $confirmed ? $confirmedMomentMs : null;
        $suggested = null;

        foreach ($countingCandidates as $candidate) {
            if ($candidate->eventId === $suggestion->candidateEventId) {
                $suggested = $candidate;
            }
        }

        $detectedCandidate = $confirmedMoment !== null
            ? self::nearestCandidate($countingCandidates, $confirmedMoment)
            : ($suggested ?? ($referenceTap === null ? null : self::nearestCandidate($countingCandidates, $referenceTap->momentMs)) ?? $firstCandidate);
        $deltaCandidate = $referenceTap === null ? null : self::nearestCandidate($countingCandidates, $referenceTap->momentMs);
        $missedRaises = null;
        $falseRaises = null;
        $detectorNotRunning = null;

        if ($detection !== OnlineDetectionMode::Off) {
            $missedRaises = 0;
            $detectorNotRunning = 0;

            foreach ($counting as $tap) {
                if (self::nearestCandidate($countingCandidates, $tap->momentMs) !== null) {
                    continue;
                }

                if (self::detectorRunningAt($events, $tap)) {
                    $missedRaises++;
                } else {
                    $detectorNotRunning++;
                }
            }

            // The finish: the confirmed one - before a confirmation the suggested one is presumed it
            $finish = $confirmedMoment !== null ? $detectedCandidate : $suggested;
            $falseRaises = count(array_filter(
                $countingCandidates,
                static fn (OnlineReviewCandidate $candidate): bool => $candidate !== $finish
                    && self::nearestTap($counting, $candidate->momentMs) === null
                    && ($confirmedMoment === null || abs($candidate->momentMs - $confirmedMoment) > self::MATCH_WINDOW_MS),
            ));
        }

        return new self(
            taps: $taps,
            suggestion: $suggestion,
            status: $status,
            flags: self::flags($clock, $nowMs, $events, $runTaps, $counting, $storedFlags),
            adjustedTap: $adjustedTap,
            adjustmentMs: $adjustedTap === null || $confirmedMomentMs === null ? null : $confirmedMomentMs - $adjustedTap->momentMs,
            candidates: $candidates,
            referenceTap: $referenceTap,
            detectedCandidate: $detectedCandidate,
            detectionErrorMs: $confirmedMoment === null || $detectedCandidate === null ? null : $confirmedMoment - $detectedCandidate->momentMs,
            deltaCandidate: $deltaCandidate,
            deltaMs: $referenceTap === null || $deltaCandidate === null ? null : $referenceTap->momentMs - $deltaCandidate->momentMs,
            missedRaises: $missedRaises,
            falseRaises: $falseRaises,
            detectorNotRunning: $detectorNotRunning,
        );
    }

    /**
     * Did the tap's phone have its finish detector watching the round then? Its own reports up to the tap decide - the
     * latest of its `device.ready` (the `detector` it was ready with: ready and a set-up test neither failed nor
     * skipped; none at all = a page loaded while the round's detection was off) and its `detector.changed` (`running`,
     * stream P2F). No report: not running.
     *
     * @param list<OnlineReviewEventRow> $events by moment
     */
    private static function detectorRunningAt(array $events, OnlineReviewTap $tap): bool
    {
        $running = false;

        foreach ($events as $event) {
            if ($event['client_ms'] > $tap->momentMs) {
                break;
            }

            if ($event['device_id'] !== $tap->deviceId) {
                continue;
            }

            if ($event['type'] === OnlineEventType::DeviceReady->value) {
                $detector = $event['payload']['detector'] ?? null;
                $running = is_array($detector)
                    && ($detector['state'] ?? null) === 'ready'
                    && in_array($detector['setupTest'] ?? null, ['failed', 'skipped'], true) === false;
            } elseif ($event['type'] === OnlineEventType::DetectorChanged->value) {
                $running = ($event['payload']['running'] ?? null) === true;
            }
        }

        return $running;
    }

    /**
     * Of the candidates, the one nearest to a moment within MATCH_WINDOW_MS - the earlier one on a tie
     *
     * @param list<OnlineReviewCandidate> $candidates by moment
     */
    private static function nearestCandidate(array $candidates, int $momentMs): null|OnlineReviewCandidate
    {
        $nearest = null;

        foreach ($candidates as $candidate) {
            $distance = abs($candidate->momentMs - $momentMs);

            if ($distance <= self::MATCH_WINDOW_MS && ($nearest === null || $distance < abs($nearest->momentMs - $momentMs))) {
                $nearest = $candidate;
            }
        }

        return $nearest;
    }

    /**
     * Of the taps, the one nearest to a moment within MATCH_WINDOW_MS
     *
     * @param list<OnlineReviewTap> $taps
     */
    private static function nearestTap(array $taps, int $momentMs): null|OnlineReviewTap
    {
        $nearest = null;

        foreach ($taps as $tap) {
            $distance = abs($tap->momentMs - $momentMs);

            if ($distance <= self::MATCH_WINDOW_MS && ($nearest === null || $distance < abs($nearest->momentMs - $momentMs))) {
                $nearest = $tap;
            }
        }

        return $nearest;
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
     * The finish candidates with what the phone's detector said about each (the `finish.candidate` payload - any value
     * of the wrong type or outside its set is left out)
     *
     * @param list<OnlineReviewEventRow> $events by moment
     * @return list<OnlineReviewCandidate>
     */
    private static function candidates(RoundClock $clock, array $events): array
    {
        [$startMs, $endMs] = self::window($clock);
        $candidates = [];

        foreach ($events as $event) {
            if ($event['type'] !== OnlineEventType::FinishCandidate->value) {
                continue;
            }

            $momentMs = $event['client_ms'];
            $position = match (true) {
                $startMs === null || $momentMs < $startMs => OnlineReviewTap::BEFORE_START,
                $endMs !== null && $momentMs > $endMs => OnlineReviewTap::AFTER_END,
                default => OnlineReviewTap::IN_ROUND,
            };
            $payload = $event['payload'];
            $score = $payload['score'] ?? null;
            $view = $payload['view'] ?? null;
            $mode = $payload['mode'] ?? null;

            $candidates[] = new OnlineReviewCandidate(
                eventId: $event['id'],
                momentMs: $momentMs,
                receivedAtMs: $event['received_at_ms'],
                deviceId: $event['device_id'],
                position: $position,
                lateMs: max(0, $event['received_at_ms'] - $momentMs),
                heardMeanwhile: $event['heard_meanwhile'],
                seconds: $position === OnlineReviewTap::IN_ROUND ? intdiv($momentMs - $startMs, 1000) : null,
                detectedAtMs: self::integer($payload['detectedAtMs'] ?? null),
                heldMs: self::integer($payload['heldMs'] ?? null),
                score: (is_int($score) || is_float($score)) && is_finite((float) $score) ? round((float) $score, 3) : null,
                view: is_string($view) && in_array($view, self::VIEWS, true) ? $view : null,
                mode: is_string($mode) && OnlineDetectionMode::tryFrom($mode) !== null ? $mode : null,
                machineVersion: self::integer($payload['machineVersion'] ?? null),
            );
        }

        return $candidates;
    }

    /**
     * A whole number of the payload - JSON may carry one as `12.0`, a moment may come with a fraction
     */
    private static function integer(mixed $value): null|int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_float($value) && is_finite($value) && abs($value) < 2 ** 53 ? (int) round($value) : null;
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
     * @param list<OnlineReviewTap> $taps the run's own (from its start on)
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

        $devices = $startMs === null ? [] : self::devicesOfTheRun($events, $startMs);

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
     * The devices of the run: the one holding the entry at its start (the last `device.ready` before it) and every
     * device that sent anything from the start on
     *
     * @param list<OnlineReviewEventRow> $events by moment
     * @return list<string>
     */
    private static function devicesOfTheRun(array $events, int $startMs): array
    {
        $holderAtStart = null;
        $devices = [];

        foreach ($events as $event) {
            if ($event['client_ms'] >= $startMs) {
                $devices[$event['device_id']] = true;
            } elseif ($event['type'] === OnlineEventType::DeviceReady->value) {
                $holderAtStart = $event['device_id'];
            }
        }

        if ($holderAtStart !== null) {
            $devices[$holderAtStart] = true;
        }

        return array_keys($devices);
    }

    /**
     * How often and how long (ms) something was down while the round ran: per device, from a `down` event to the next
     * `up` one, counted from the start of the window. A down without its up lasts until the end of the window - when it
     * came in the run: one from before the start may be an earlier run's, and a later down of the same device in the
     * run means it came back meanwhile (a reload records no return). Null when it never was.
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
                if (!isset($openSince[$device]) || ($openSince[$device] < $fromMs && $event['client_ms'] >= $fromMs)) {
                    $openSince[$device] = $event['client_ms'];
                }
            } elseif ($event['type'] === $up->value && isset($openSince[$device])) {
                $intervals[] = [$openSince[$device], $event['client_ms']];
                unset($openSince[$device]);
            }
        }

        foreach ($openSince as $since) {
            if ($since >= $fromMs) {
                $intervals[] = [$since, max($since, $untilMs)];
            }
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
