<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Query\GetOnlineFinishPhotoChecks;
use SpeedPuzzling\Web\Query\GetOnlineMomentVerdicts;
use SpeedPuzzling\Web\Results\OnlineAccuracyGroup;
use SpeedPuzzling\Web\Results\OnlineAccuracyRow;
use SpeedPuzzling\Web\Results\OnlineReviewEntry;
use SpeedPuzzling\Web\Value\AccuracySummary;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineDeviceLabel;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
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
 * (confirmed − detected), the delta (tap − detected), the missed and the false raises - and (stream P2F) the finishes on a
 * phone whose detector did not watch the round (`detectorNotRunning`: it could not run, broke, was skipped, failed its
 * test, stopped as too slow, or the page was loaded before detection was switched on - from the phone's own
 * `device.ready` / `detector.changed`), counted apart, never as missed raises; MEASURES names the distributions
 * (one summary each). The breakdown groups the participants by their phone (OnlineDeviceLabel, from the User-Agent of
 * the holding device's latest `device.ready`) and by the camera view their detector used (the candidates' own `view`,
 * else the one `device.ready` reported) - "autodetection reliability is the key to success" (Jan): this is what every
 * detector change is judged by.
 *
 * The reviewers' gap marks (the G key stored, stream RG - GetOnlineMomentVerdicts, OnlineReviewAssessment) split the
 * raises that were not the finish: **premature raises** (the reviewer marked the raise's photo as a gap - the
 * participant raised too early, the detector was right) and **false detections** (not marked, no tap near - the
 * detector's own); the taps marked as a gap are **premature taps**. In the summary (`raises`, `prematureTaps`), per
 * row, in the breakdowns and in three CSV columns appended at the end.
 *
 * Phase 3 - the completeness check (README §5.8, advisory), only while it is on: per confirmed participant its verdict
 * on the confirmed photo against the reviewer's outcome (completenessOf()) - the photo of the finish moment the
 * reviewer confirmed (the tap or candidate nearest to the confirmed moment, as the review opens it; in a `shadow` round
 * the taps only) for a confirmed finish (truth: complete), the photo of the last finish moment - else the end photo -
 * for a confirmed unfinished puzzle (truth: not complete). Outcomes: agree, false complete (a missing piece passed -
 * the one error that matters), false gap, unsure, not checked (pending, or the check failed).
 *
 * @phpstan-import-type OnlineReviewEventRow from \SpeedPuzzling\Web\Query\GetOnlineReviewData
 * @phpstan-import-type OnlineFinishPhotoRow from GetOnlineFinishPhotoChecks
 */
readonly final class OnlineAccuracy
{
    public const string ADJUSTMENT = 'adjustment';
    public const string DETECTION_ERROR = 'detection_error';
    public const string DELTA = 'delta';

    // The distributions the report measures, in order
    public const array MEASURES = [self::ADJUSTMENT, self::DETECTION_ERROR, self::DELTA];

    // The completeness check's verdict on the confirmed photo against the reviewer's outcome (Phase 3)
    public const string OUTCOME_AGREE = 'agree';
    public const string OUTCOME_FALSE_COMPLETE = 'false_complete';
    public const string OUTCOME_FALSE_GAP = 'false_gap';
    public const string OUTCOME_UNSURE = 'unsure';
    public const string OUTCOME_NOT_CHECKED = 'not_checked';
    public const array OUTCOMES = [self::OUTCOME_AGREE, self::OUTCOME_FALSE_COMPLETE, self::OUTCOME_FALSE_GAP, self::OUTCOME_UNSURE, self::OUTCOME_NOT_CHECKED];

    public function __construct(
        private OnlineReview $onlineReview,
        private ClockInterface $clock,
        private OnlineCvSettings $onlineCvSettings,
        private GetOnlineFinishPhotoChecks $getOnlineFinishPhotoChecks,
        private GetOnlineMomentVerdicts $getOnlineMomentVerdicts,
    ) {
    }

    /**
     * @return array{rows: list<OnlineAccuracyRow>, summaries: array<string, AccuracySummary>, startedAtMs: null|int, detection: string, detectionShown: bool, byDevice: list<OnlineAccuracyGroup>, byView: list<OnlineAccuracyGroup>, detectorNotRunning: int, completeness: null|array<string, int>, raises: array{missed: int, premature: int, falseDetections: int}, prematureTaps: int}
     */
    public function forRound(CompetitionRound $round, OnlineRound $onlineRound): array
    {
        $now = $this->clock->now();
        $clock = RoundClock::fromRound($round, $now);
        [$startedAtMs] = OnlineReviewAssessment::window($clock);
        $detection = $onlineRound->detectionMode;
        $rows = [];
        $anyCandidate = false;
        $checkOn = $this->onlineCvSettings->isOn();
        $photos = $checkOn ? $this->getOnlineFinishPhotoChecks->forRound($round->id->toString()) : [];

        // The reviewers' gap marks (the G key, stream RG) - every one, a `shadow` round's raises too
        $gaps = $this->getOnlineMomentVerdicts->gapsOfRound($round->id->toString());

        foreach ($this->onlineReview->assessed($round, $detection, $clock, RoundClock::milliseconds($now), gaps: $gaps) as $assessed) {
            $entry = $assessed['entry'];
            $assessment = $assessed['assessment'];
            $tap = $assessment->referenceTap;
            $detected = $assessment->detectedCandidate;
            $anyCandidate = $anyCandidate || $assessment->candidates !== [];
            [$userAgent, $readyView] = self::deviceReady($assessed['events'], $assessed['activeDeviceId']);
            $label = $userAgent === null ? null : OnlineDeviceLabel::fromUserAgent($userAgent);
            [$completenessVerdict, $completenessOutcome] = $checkOn
                ? self::completenessOf($entry, $assessment, $photos[$entry->onlineEntryId ?? ''] ?? [], $detection)
                : [null, null];

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
                detectorNotRunning: $assessment->detectorNotRunning,
                completenessVerdict: $completenessVerdict,
                completenessOutcome: $completenessOutcome,
                prematureRaises: $assessment->prematureRaises,
                falseDetections: $assessment->falseDetections,
                prematureTaps: $assessment->prematureTaps,
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
            'detectorNotRunning' => array_sum(array_map(static fn (OnlineAccuracyRow $row): int => $row->detectorNotRunning ?? 0, $rows)),
            'completeness' => $checkOn ? self::completenessCounts($rows) : null,
            'raises' => [
                'missed' => self::total($rows, static fn (OnlineAccuracyRow $row): null|int => $row->missedRaises),
                'premature' => self::total($rows, static fn (OnlineAccuracyRow $row): null|int => $row->prematureRaises),
                'falseDetections' => self::total($rows, static fn (OnlineAccuracyRow $row): null|int => $row->falseDetections),
            ],
            'prematureTaps' => self::total($rows, static fn (OnlineAccuracyRow $row): null|int => $row->prematureTaps),
        ];
    }

    /**
     * How many confirmed participants' photos the check got right, passed falsely as complete, flagged falsely, was not
     * sure about, or did not check - `compared` = all of them
     *
     * @param list<OnlineAccuracyRow> $rows
     * @return array<string, int>
     */
    private static function completenessCounts(array $rows): array
    {
        $counts = array_fill_keys(self::OUTCOMES, 0);

        foreach ($rows as $row) {
            if ($row->completenessOutcome !== null) {
                $counts[$row->completenessOutcome]++;
            }
        }

        $counts['compared'] = array_sum($counts);

        return $counts;
    }

    /**
     * The check's verdict on the confirmed photo and the outcome against the reviewer's decision - [null, null] before
     * a confirmation, for a participant who did not start, or without a photo
     *
     * @param list<OnlineFinishPhotoRow> $photos
     * @return array{0: null|string, 1: null|string}
     */
    private static function completenessOf(OnlineReviewEntry $entry, OnlineReviewAssessment $assessment, array $photos, OnlineDetectionMode $detection): array
    {
        if ($entry->status !== OnlineReviewAssessment::STATUS_CONFIRMED) {
            return [null, null];
        }

        // The finish moments the reviewer saw - a `shadow` round shows the taps only
        $moments = [];

        if ($detection !== OnlineDetectionMode::Shadow) {
            foreach ($assessment->candidates as $candidate) {
                $moments[] = ['kind' => OnlineMediaKind::Candidate, 'eventId' => $candidate->eventId, 'momentMs' => $candidate->momentMs];
            }
        }

        foreach ($assessment->taps as $tap) {
            $moments[] = ['kind' => OnlineMediaKind::Button, 'eventId' => $tap->eventId, 'momentMs' => $tap->momentMs];
        }

        // In time, a candidate before a tap of the same moment (review_logic.js finishMoments())
        usort($moments, static fn (array $a, array $b): int => [$a['momentMs'], $a['kind'] === OnlineMediaKind::Candidate ? 0 : 1] <=> [$b['momentMs'], $b['kind'] === OnlineMediaKind::Candidate ? 0 : 1]);

        if ($entry->official->seconds !== null) {
            $truthComplete = true;
            $moment = null;

            if ($entry->confirmedMomentMs !== null) {
                foreach ($moments as $finishMoment) {
                    if ($moment === null || abs($finishMoment['momentMs'] - $entry->confirmedMomentMs) < abs($moment['momentMs'] - $entry->confirmedMomentMs)) {
                        $moment = $finishMoment;
                    }
                }
            }

            $photo = $moment === null ? null : GetOnlineFinishPhotoChecks::photoOf($photos, $moment['kind'], $moment['eventId']);
        } elseif ($entry->official->piecesPlaced !== null) {
            // Never a complete puzzle: pieces placed stay below the round puzzle's piece count - RecordRoundResults
            // refuses the full count (`invalid_pieces_placed`), a puzzle finished at the buzzer is confirmed as a time
            $truthComplete = false;
            $last = $moments === [] ? null : $moments[count($moments) - 1];
            $photo = $last === null ? null : GetOnlineFinishPhotoChecks::photoOf($photos, $last['kind'], $last['eventId']);

            if ($photo === null) {
                $ends = array_values(array_filter($photos, static fn (array $item): bool => $item['kind'] === OnlineMediaKind::End->value));
                $arrived = array_values(array_filter($ends, static fn (array $item): bool => $item['uploaded']));
                $photo = $arrived[count($arrived) - 1] ?? $ends[count($ends) - 1] ?? null;
            }
        } else {
            return [null, null];
        }

        if ($photo === null || $photo['check'] === null) {
            return [null, null];
        }

        $state = $photo['check']->state;
        $outcome = match ($state) {
            OnlineFinishCheckVerdict::Complete->value => $truthComplete ? self::OUTCOME_AGREE : self::OUTCOME_FALSE_COMPLETE,
            OnlineFinishCheckVerdict::Gaps->value => $truthComplete ? self::OUTCOME_FALSE_GAP : self::OUTCOME_AGREE,
            OnlineFinishCheckVerdict::Unsure->value => self::OUTCOME_UNSURE,
            default => self::OUTCOME_NOT_CHECKED,
        };

        return [$state, $outcome];
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
     * The sum of a count over the rows - a row without it (null) counts nothing
     *
     * @param list<OnlineAccuracyRow> $rows
     * @param \Closure(OnlineAccuracyRow): (null|int) $count
     */
    private static function total(array $rows, \Closure $count): int
    {
        return array_sum(array_map(static fn (OnlineAccuracyRow $row): int => $count($row) ?? 0, $rows));
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
                missedRaises: self::total($members, static fn (OnlineAccuracyRow $row): null|int => $row->missedRaises),
                prematureRaises: self::total($members, static fn (OnlineAccuracyRow $row): null|int => $row->prematureRaises),
                falseDetections: self::total($members, static fn (OnlineAccuracyRow $row): null|int => $row->falseDetections),
                detectionError: self::summary($members, static fn (OnlineAccuracyRow $row): null|int => $row->detectionErrorMs),
                delta: self::summary($members, static fn (OnlineAccuracyRow $row): null|int => $row->deltaMs),
                detectorNotRunning: array_sum(array_map(static fn (OnlineAccuracyRow $row): int => $row->detectorNotRunning ?? 0, $members)),
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
