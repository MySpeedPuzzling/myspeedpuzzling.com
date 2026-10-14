<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Results\OnlineAccuracyGroup;
use SpeedPuzzling\Web\Results\OnlineAccuracyRow;
use SpeedPuzzling\Web\Value\AccuracySummary;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineDeviceLabel;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineReviewAssessment;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * The accuracy report of an online round (docs/features/online-competitions/README.md §5.9 "Accuracy report"): per
 * participant the tap, the confirmed moment, the reviewer's adjustment (confirmed − tap), its source and how late the
 * tap arrived (OnlineAccuracyRow), and the distribution of the adjustment (AccuracySummary) - how far the Finished
 * button is from the moment a person confirmed. Read from the review's own assessment (OnlineReview::assessed()), so
 * the report and the review never disagree.
 *
 * Phase 2 - the detection of raised hands (OnlineReviewAssessment "Detection"): the detected moment, the detection error
 * (confirmed − detected), the delta (tap − detected), the missed and the false raises; MEASURES names the distributions
 * (one summary each). The breakdown groups the participants by their phone (OnlineDeviceLabel, from the User-Agent of
 * the holding device's latest `device.ready`) and by the camera view their detector used (the candidates' own `view`,
 * else the one `device.ready` reported) - "autodetection reliability is the key to success" (Jan): this is what every
 * detector change is judged by.
 *
 * @phpstan-import-type OnlineReviewEventRow from \SpeedPuzzling\Web\Query\GetOnlineReviewData
 */
readonly final class OnlineAccuracy
{
    public const string ADJUSTMENT = 'adjustment';
    public const string DETECTION_ERROR = 'detection_error';
    public const string DELTA = 'delta';

    // The distributions the report measures, in order
    public const array MEASURES = [self::ADJUSTMENT, self::DETECTION_ERROR, self::DELTA];

    public function __construct(
        private OnlineReview $onlineReview,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{rows: list<OnlineAccuracyRow>, summaries: array<string, AccuracySummary>, startedAtMs: null|int, detection: string, detectionShown: bool, byDevice: list<OnlineAccuracyGroup>, byView: list<OnlineAccuracyGroup>}
     */
    public function forRound(CompetitionRound $round, OnlineRound $onlineRound): array
    {
        $now = $this->clock->now();
        $clock = RoundClock::fromRound($round, $now);
        [$startedAtMs] = OnlineReviewAssessment::window($clock);
        $detection = $onlineRound->detectionMode;
        $rows = [];
        $anyCandidate = false;

        foreach ($this->onlineReview->assessed($round, $detection, $clock, RoundClock::milliseconds($now)) as $assessed) {
            $entry = $assessed['entry'];
            $assessment = $assessed['assessment'];
            $tap = $assessment->referenceTap;
            $detected = $assessment->detectedCandidate;
            $anyCandidate = $anyCandidate || $assessment->candidates !== [];
            [$userAgent, $readyView] = self::deviceReady($assessed['events'], $assessed['activeDeviceId']);
            $label = $userAgent === null ? null : OnlineDeviceLabel::fromUserAgent($userAgent);

            $rows[] = new OnlineAccuracyRow(
                participantId: $entry->participantId,
                name: $entry->name,
                status: $entry->status,
                tapMomentMs: $tap?->momentMs,
                tapElapsedMs: $tap === null || $startedAtMs === null ? null : $tap->momentMs - $startedAtMs,
                confirmedMomentMs: $entry->confirmedMomentMs,
                confirmedElapsedMs: $entry->confirmedMomentMs === null || $startedAtMs === null ? null : $entry->confirmedMomentMs - $startedAtMs,
                adjustmentMs: $assessment->adjustmentMs,
                source: $entry->confirmedSource,
                arrivedLateMs: $tap?->lateMs,
                official: $entry->official,
                detectedMomentMs: $detected?->momentMs,
                detectedElapsedMs: $detected === null || $startedAtMs === null ? null : $detected->momentMs - $startedAtMs,
                detectionErrorMs: $assessment->detectionErrorMs,
                deltaMs: $assessment->deltaMs,
                missedRaises: $assessment->missedRaises,
                falseRaises: $assessment->falseRaises,
                view: self::viewOf($assessment) ?? $readyView,
                device: $label?->device,
                browser: $label?->browser,
            );
        }

        // In finishing order - those without a tap after them, by name as the review lists them
        usort($rows, static fn (OnlineAccuracyRow $a, OnlineAccuracyRow $b): int => [self::finishMs($a) === null, self::finishMs($a) ?? 0] <=> [self::finishMs($b) === null, self::finishMs($b) ?? 0]);

        return [
            'rows' => $rows,
            'summaries' => [
                self::ADJUSTMENT => self::summary($rows, static fn (OnlineAccuracyRow $row): null|int => $row->adjustmentMs),
                self::DETECTION_ERROR => self::summary($rows, static fn (OnlineAccuracyRow $row): null|int => $row->detectionErrorMs),
                self::DELTA => self::summary($rows, static fn (OnlineAccuracyRow $row): null|int => $row->deltaMs),
            ],
            'startedAtMs' => $startedAtMs,
            'detection' => $detection->value,
            // A round with detection off shows the Phase 1 report - unless candidates came all the same
            'detectionShown' => $detection !== OnlineDetectionMode::Off || $anyCandidate,
            'byDevice' => self::groups($rows, static fn (OnlineAccuracyRow $row): null|string => $row->device === null ? null : $row->device . ' · ' . $row->browser),
            'byView' => self::groups($rows, static fn (OnlineAccuracyRow $row): null|string => $row->view),
        ];
    }

    /**
     * The tap, else the detected moment - the finishing order
     */
    private static function finishMs(OnlineAccuracyRow $row): null|int
    {
        return $row->tapMomentMs ?? $row->detectedMomentMs;
    }

    /**
     * @param list<OnlineAccuracyRow> $rows
     * @param \Closure(OnlineAccuracyRow): (null|int) $value
     */
    private static function summary(array $rows, \Closure $value): AccuracySummary
    {
        return AccuracySummary::of(array_values(array_filter(array_map($value, $rows), static fn (null|int $item): bool => $item !== null)));
    }

    /**
     * The participants grouped by a label, the largest group first (a tie by label, the unknown last)
     *
     * @param list<OnlineAccuracyRow> $rows
     * @param \Closure(OnlineAccuracyRow): (null|string) $labelOf
     * @return list<OnlineAccuracyGroup>
     */
    private static function groups(array $rows, \Closure $labelOf): array
    {
        /** @var array<string, list<OnlineAccuracyRow>> $byLabel */
        $byLabel = [];

        foreach ($rows as $row) {
            $byLabel[$labelOf($row) ?? ''][] = $row;
        }

        $groups = [];

        foreach ($byLabel as $label => $members) {
            $groups[] = new OnlineAccuracyGroup(
                label: $label === '' ? null : (string) $label,
                participants: count($members),
                finishes: count(array_filter($members, static fn (OnlineAccuracyRow $row): bool => $row->tapMomentMs !== null || $row->detectedMomentMs !== null || $row->confirmedMomentMs !== null)),
                detected: count(array_filter($members, static fn (OnlineAccuracyRow $row): bool => $row->detectedMomentMs !== null)),
                missedRaises: array_sum(array_map(static fn (OnlineAccuracyRow $row): int => $row->missedRaises ?? 0, $members)),
                falseRaises: array_sum(array_map(static fn (OnlineAccuracyRow $row): int => $row->falseRaises ?? 0, $members)),
                detectionError: self::summary($members, static fn (OnlineAccuracyRow $row): null|int => $row->detectionErrorMs),
                delta: self::summary($members, static fn (OnlineAccuracyRow $row): null|int => $row->deltaMs),
            );
        }

        usort($groups, static fn (OnlineAccuracyGroup $a, OnlineAccuracyGroup $b): int => [$a->label === null, -$a->participants, $a->label ?? ''] <=> [$b->label === null, -$b->participants, $b->label ?? '']);

        return $groups;
    }

    /**
     * The view the participant's detector used: the detected candidate's, else the most recent candidate's
     */
    private static function viewOf(OnlineReviewAssessment $assessment): null|string
    {
        if ($assessment->detectedCandidate?->view !== null) {
            return $assessment->detectedCandidate->view;
        }

        foreach (array_reverse($assessment->candidates) as $candidate) {
            if ($candidate->view !== null) {
                return $candidate->view;
            }
        }

        return null;
    }

    /**
     * The phone of the entry: the latest `device.ready` of the device holding it (else of any device) - its User-Agent
     * and the camera view its detector reported
     *
     * @param list<OnlineReviewEventRow> $events by moment
     * @return array{0: null|string, 1: null|string}
     */
    private static function deviceReady(array $events, null|string $activeDeviceId): array
    {
        $latest = null;
        $latestOfHolder = null;

        foreach ($events as $event) {
            if ($event['type'] !== OnlineEventType::DeviceReady->value) {
                continue;
            }

            $latest = $event;

            if ($event['device_id'] === $activeDeviceId) {
                $latestOfHolder = $event;
            }
        }

        $ready = $latestOfHolder ?? $latest;

        if ($ready === null) {
            return [null, null];
        }

        $userAgent = $ready['payload']['userAgent'] ?? null;
        $detector = $ready['payload']['detector'] ?? null;
        $view = is_array($detector) ? ($detector['view'] ?? null) : null;

        return [
            is_string($userAgent) && $userAgent !== '' ? $userAgent : null,
            is_string($view) && in_array($view, OnlineReviewAssessment::VIEWS, true) ? $view : null,
        ];
    }
}
