<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\OnlineReviewCandidate;
use SpeedPuzzling\Web\Results\OnlineReviewFlag;
use SpeedPuzzling\Web\Results\OnlineReviewSuggestion;
use SpeedPuzzling\Web\Results\OnlineReviewTap;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineReviewAssessment;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * What the review reads from one participant's events (OnlineReviewAssessment, docs/features/online-competitions/
 * README.md §5.9, §5.10, §7): the taps that count, the suggestion (the last tap that counts - the moment the
 * participant's own page shows -, else the pieces reported, else none), the status, the flags and the reviewer's
 * adjustment.
 */
final class OnlineReviewAssessmentTest extends TestCase
{
    // A whole second, like every stored start
    private const int START = 1_792_000_000_000;
    private const int MINUTE = 60_000;

    private static int $sequence = 0;

    public function testTheLastTapThatCountsIsTheSuggestion(): void
    {
        $first = self::event('finish.button', self::START + 40 * self::MINUTE, id: 'tap-1');
        $second = self::event('finish.button', self::START + 47 * self::MINUTE + 12_345, id: 'tap-2');
        $mistake = self::event('finish.button', self::START + 48 * self::MINUTE, id: 'tap-3');
        $undo = self::event('finish.button.undone', self::START + 48 * self::MINUTE + 3_000, ['eventId' => 'TAP-3']);

        $assessment = self::assess([$mistake, $undo, $second, $first]);

        self::assertSame(['tap-1', 'tap-2', 'tap-3'], array_map(static fn (OnlineReviewTap $tap): string => $tap->eventId, $assessment->taps), 'by moment');
        self::assertTrue($assessment->taps[2]->undone, 'an undo names its tap - in any case');
        self::assertSame(OnlineReviewSuggestion::FINISHED, $assessment->suggestion->kind);
        self::assertSame('tap-2', $assessment->suggestion->tapEventId);
        self::assertSame(47 * 60 + 12, $assessment->suggestion->seconds);
        self::assertSame(OnlineReviewAssessment::STATUS_NEEDS_LOOK, $assessment->status);
        self::assertSame([OnlineReviewFlag::SEVERAL_TAPS => 2], self::flagCounts($assessment));
    }

    public function testATapOutsideTheRoundDoesNotCount(): void
    {
        $clock = RoundClock::fromStopwatch('stopped', self::moment(self::START), self::moment(self::START + 30 * self::MINUTE), 60, self::moment(self::START + 40 * self::MINUTE));

        $assessment = self::assess([
            self::event('finish.button', self::START - 2_000, id: 'early'),
            self::event('finish.button', self::START + 31 * self::MINUTE, id: 'late'),
        ], clock: $clock);

        self::assertSame([OnlineReviewTap::BEFORE_START, OnlineReviewTap::AFTER_END], array_map(static fn (OnlineReviewTap $tap): string => $tap->position, $assessment->taps));
        self::assertSame([null, null], array_map(static fn (OnlineReviewTap $tap): null|int => $tap->seconds, $assessment->taps));
        self::assertSame(OnlineReviewSuggestion::NONE, $assessment->suggestion->kind);
        self::assertSame(OnlineReviewAssessment::STATUS_NO_FINISH, $assessment->status);
        self::assertSame([OnlineReviewFlag::TAP_AFTER_END => 1], self::flagCounts($assessment));
    }

    public function testBeforeTheStartNothingCounts(): void
    {
        $clock = RoundClock::fromStopwatch(null, null, null, 60, self::moment(self::START));

        $assessment = self::assess([self::event('finish.button', self::START)], clock: $clock);

        self::assertSame(OnlineReviewTap::BEFORE_START, $assessment->taps[0]->position);
        self::assertSame(OnlineReviewAssessment::STATUS_NO_FINISH, $assessment->status);
    }

    public function testWithoutATapThePiecesReportedAreTheSuggestion(): void
    {
        $left = self::assess([], reportedMeasure: 'left', reportedPieces: 312, piecesTotal: 3000);
        self::assertSame(OnlineReviewSuggestion::UNFINISHED, $left->suggestion->kind);
        self::assertSame(2688, $left->suggestion->piecesPlaced);
        self::assertSame(OnlineReviewAssessment::STATUS_UNFINISHED, $left->status);

        self::assertSame(312, self::assess([], reportedMeasure: 'placed', reportedPieces: 312, piecesTotal: 3000)->suggestion->piecesPlaced);
        self::assertNull(self::assess([], reportedMeasure: 'left', reportedPieces: 312, piecesTotal: null)->suggestion->piecesPlaced, 'several puzzles: no piece count');
        self::assertNull(OnlineReviewAssessment::piecesPlaced(null, 312, 3000));
        self::assertSame(0, OnlineReviewAssessment::piecesPlaced('left', 3500, 3000));

        // A tap that counts beats a report
        self::assertSame(OnlineReviewSuggestion::FINISHED, self::assess([self::event('finish.button', self::START + self::MINUTE)], reportedMeasure: 'left', reportedPieces: 1, piecesTotal: 3000)->suggestion->kind);
    }

    public function testAConfirmedEntryIsConfirmedAndAdjustedAgainstTheNearestTap(): void
    {
        $tapMs = self::START + 50 * self::MINUTE;

        $assessment = self::assess([
            self::event('finish.button', self::START + 20 * self::MINUTE, id: 'far'),
            self::event('finish.button', $tapMs, id: 'near', received: $tapMs + 45_000),
        ], confirmed: true, confirmedMomentMs: $tapMs - 1_250);

        self::assertSame(OnlineReviewAssessment::STATUS_CONFIRMED, $assessment->status);
        self::assertSame('near', $assessment->adjustedTap?->eventId);
        self::assertSame(-1_250, $assessment->adjustmentMs);
        self::assertSame(45_000, $assessment->adjustedTap->lateMs);

        // Confirmed unfinished: nothing to adjust
        self::assertNull(self::assess([self::event('finish.button', $tapMs)], confirmed: true)->adjustmentMs);
    }

    public function testALateTapIsFlaggedOnlyWhileThePhoneKeptSending(): void
    {
        $tapMs = self::START + 30 * self::MINUTE;

        $offline = self::assess([self::event('finish.button', $tapMs, received: $tapMs + 120_000, heard: false)]);
        self::assertArrayNotHasKey(OnlineReviewFlag::ARRIVED_LATE, self::flagCounts($offline), 'offline the whole time - nothing to look at');

        $kept = self::assess([self::event('finish.button', $tapMs, received: $tapMs + 95_500, heard: true)]);
        $flag = self::flag($kept, OnlineReviewFlag::ARRIVED_LATE);
        self::assertSame(1, $flag?->count);
        self::assertSame(95, $flag->seconds);

        $quick = self::assess([self::event('finish.button', $tapMs, received: $tapMs + 29_000, heard: true)]);
        self::assertArrayNotHasKey(OnlineReviewFlag::ARRIVED_LATE, self::flagCounts($quick), 'under LATE_ARRIVAL_MS');
    }

    public function testOutagesCountHowOftenAndHowLongWhileTheRoundRan(): void
    {
        $assessment = self::assess([
            // Before the start - not the round's business
            self::event('camera.lost', self::START - 5 * self::MINUTE),
            self::event('camera.back', self::START - 4 * self::MINUTE),
            self::event('camera.lost', self::START + 10 * self::MINUTE),
            self::event('camera.back', self::START + 10 * self::MINUTE + 4_000),
            // Starts before the start, counted from it
            self::event('page.hidden', self::START - 3_000),
            self::event('page.visible', self::START + 7_000),
            self::event('page.hidden', self::START + 20 * self::MINUTE),
            self::event('page.visible', self::START + 20 * self::MINUTE + 1_000),
            // Never came back online: until now (the round still runs)
            self::event('network.offline', self::START + 59 * self::MINUTE),
        ], nowMs: self::START + 59 * self::MINUTE + 30_000);

        self::assertEquals(new OnlineReviewFlag(OnlineReviewFlag::CAMERA_LOST, 1, 4), self::flag($assessment, OnlineReviewFlag::CAMERA_LOST));
        self::assertEquals(new OnlineReviewFlag(OnlineReviewFlag::PAGE_HIDDEN, 2, 8), self::flag($assessment, OnlineReviewFlag::PAGE_HIDDEN));
        self::assertEquals(new OnlineReviewFlag(OnlineReviewFlag::OFFLINE, 1, 30), self::flag($assessment, OnlineReviewFlag::OFFLINE));
    }

    /**
     * Stream RP: a page (re)opened while the round ran (`page.reloaded`) is a flag of its own - counted in the run only,
     * on any device - and the page it replaced went hidden as it went: the reload is that page's return, never a page
     * hidden until the end
     */
    public function testAReloadIsAFlagAndThePageHiddenAsItWentComesBackWithIt(): void
    {
        $assessment = self::assess([
            // A reload before the start (the waiting screen) is no incident of the round
            self::event('page.reloaded', self::START - 2 * self::MINUTE, ['goneMs' => 3_000]),
            // The old page goes hidden as it is reloaded, the new one says so 5 s later
            self::event('page.hidden', self::START + 10 * self::MINUTE),
            self::event('page.reloaded', self::START + 10 * self::MINUTE + 5_000, ['goneMs' => 5_000, 'screen' => 'running']),
            // A reload the phone could not time
            self::event('page.reloaded', self::START + 30 * self::MINUTE, ['goneMs' => null]),
        ], nowMs: self::START + 59 * self::MINUTE);

        self::assertEquals(new OnlineReviewFlag(OnlineReviewFlag::PAGE_RELOADED, 2), self::flag($assessment, OnlineReviewFlag::PAGE_RELOADED));
        self::assertEquals(new OnlineReviewFlag(OnlineReviewFlag::PAGE_HIDDEN, 1, 5), self::flag($assessment, OnlineReviewFlag::PAGE_HIDDEN), 'hidden for 5 s, not for 49 minutes');
        self::assertSame([OnlineReviewFlag::PAGE_HIDDEN, OnlineReviewFlag::PAGE_RELOADED], array_map(static fn (OnlineReviewFlag $flag): string => $flag->code, $assessment->flags));

        // A reload after the end, or another run's, never counts
        $ended = RoundClock::fromStopwatch('stopped', self::moment(self::START), self::moment(self::START + 30 * self::MINUTE), 60, self::moment(self::START + 40 * self::MINUTE));
        $after = self::assess([self::event('page.reloaded', self::START + 31 * self::MINUTE, ['goneMs' => 1_000])], clock: $ended, nowMs: self::START + 40 * self::MINUTE);
        self::assertArrayNotHasKey(OnlineReviewFlag::PAGE_RELOADED, self::flagCounts($after));

        // A reload on the second device of the link counts too - and makes it a device of the run
        $second = self::assess([
            self::event('device.ready', self::START - self::MINUTE, device: 'phone-a'),
            self::event('page.reloaded', self::START + 5 * self::MINUTE, ['goneMs' => 2_000], device: 'phone-b'),
        ]);
        self::assertSame([OnlineReviewFlag::PAGE_RELOADED => 1, OnlineReviewFlag::TAKEOVER => 2], self::flagCounts($second));
    }

    /**
     * A run is one start of the round clock: the run that was reset (here two hours before) keeps its events, but
     * nothing of it counts - not its taps, not a tap of it that arrived late, not the device it was on, not a loss it
     * never came back from, not as the tap a confirmation is measured against
     */
    public function testAnEarlierRunNeverCounts(): void
    {
        $earlierRun = self::START - 2 * 60 * self::MINUTE;
        $events = [
            self::event('device.ready', $earlierRun - self::MINUTE, device: 'old-phone'),
            self::event('finish.button', $earlierRun + 40 * self::MINUTE, received: $earlierRun + 42 * self::MINUTE, device: 'old-phone', heard: true, id: 'earlier-tap'),
            self::event('camera.lost', $earlierRun + 50 * self::MINUTE, device: 'old-phone'),
            // This run, on another phone
            self::event('device.ready', self::START - 2 * self::MINUTE, device: 'phone-b'),
            self::event('finish.button', self::START + 45 * self::MINUTE, device: 'phone-b', id: 'run-tap'),
        ];

        $assessment = self::assess($events, confirmed: true, confirmedMomentMs: self::START + 45 * self::MINUTE - 500);

        self::assertSame([OnlineReviewTap::BEFORE_START, OnlineReviewTap::IN_ROUND], array_map(static fn (OnlineReviewTap $tap): string => $tap->position, $assessment->taps), 'listed, never counted');
        self::assertSame([], self::flagCounts($assessment), 'no late tap, no takeover, no camera loss of the run that was');
        self::assertSame('run-tap', $assessment->adjustedTap?->eventId);
        self::assertSame(-500, $assessment->adjustmentMs);

        // The device holding the entry at the start was the old phone: a second device in the run is a takeover
        $taken = self::assess([
            self::event('device.ready', $earlierRun - self::MINUTE, device: 'old-phone'),
            self::event('page.visible', self::START + self::MINUTE, device: 'old-phone'),
            self::event('device.ready', self::START + 5 * self::MINUTE, device: 'phone-b'),
        ]);
        self::assertSame([OnlineReviewFlag::TAKEOVER => 2], self::flagCounts($taken));

        // A loss that came back in the run counts from the start; one the old run never came back from does not - and a
        // new loss of the same device in the run counts from its own moment
        $losses = self::assess([
            self::event('camera.lost', $earlierRun + 10 * self::MINUTE),
            self::event('camera.lost', self::START + 10 * self::MINUTE),
            self::event('camera.back', self::START + 10 * self::MINUTE + 6_000),
            self::event('network.offline', $earlierRun + 20 * self::MINUTE, device: 'old-phone'),
        ]);
        self::assertEquals(new OnlineReviewFlag(OnlineReviewFlag::CAMERA_LOST, 1, 6), self::flag($losses, OnlineReviewFlag::CAMERA_LOST));
        self::assertNull(self::flag($losses, OnlineReviewFlag::OFFLINE));
    }

    public function testAnotherDeviceAndStoredFlags(): void
    {
        $assessment = self::assess([
            self::event('device.ready', self::START - self::MINUTE, device: 'phone-a'),
            self::event('device.ready', self::START + self::MINUTE, device: 'laptop'),
        ], storedFlags: ['takeover', 'checked_by_hand']);

        self::assertSame([OnlineReviewFlag::TAKEOVER => 2, 'checked_by_hand' => null], self::flagCounts($assessment), 'a stored flag the assessment found too is listed once');
    }

    public function testWithDetectionOnTheFirstCandidateThatCountsIsTheSuggestion(): void
    {
        $premature = self::candidate(self::START + 45 * self::MINUTE, id: 'raise-1', payload: ['score' => 0.71, 'view' => 'angled', 'mode' => 'on', 'detectedAtMs' => self::START + 45 * self::MINUTE + 520, 'heldMs' => 520, 'machineVersion' => 1]);
        $real = self::candidate(self::START + 47 * self::MINUTE + 12_345, id: 'raise-2');
        $tap = self::event('finish.button', self::START + 47 * self::MINUTE + 13_000, id: 'tap');
        $early = self::candidate(self::START - 5_000, id: 'before');

        $on = self::assess([$tap, $real, $premature, $early], detection: OnlineDetectionMode::On);

        self::assertSame(['before', 'raise-1', 'raise-2'], array_map(static fn (OnlineReviewCandidate $candidate): string => $candidate->eventId, $on->candidates), 'by moment');
        self::assertSame([OnlineReviewTap::BEFORE_START, OnlineReviewTap::IN_ROUND, OnlineReviewTap::IN_ROUND], array_map(static fn (OnlineReviewCandidate $candidate): string => $candidate->position, $on->candidates));
        self::assertSame(OnlineReviewSuggestion::FINISHED, $on->suggestion->kind);
        self::assertSame(OnlineReviewSuggestion::SOURCE_DETECTION, $on->suggestion->source);
        self::assertSame('raise-1', $on->suggestion->candidateEventId, 'the first raise - its photo decides (G moves on)');
        self::assertNull($on->suggestion->tapEventId);
        self::assertSame(45 * 60, $on->suggestion->seconds);
        self::assertSame(OnlineReviewAssessment::STATUS_NEEDS_LOOK, $on->status);

        // What the phone said about the raise
        $first = $on->candidates[1];
        self::assertSame(0.71, $first->score);
        self::assertSame('angled', $first->view);
        self::assertSame('on', $first->mode);
        self::assertSame(520, $first->heldMs);
        self::assertSame(1, $first->machineVersion);
        self::assertSame(45 * 60, $first->seconds);
        self::assertNull($on->candidates[2]->view, 'nothing sent - nothing made up');

        // Shadow and off: the tap is the official moment, as in Phase 1
        foreach ([OnlineDetectionMode::Shadow, OnlineDetectionMode::Off] as $mode) {
            $assessment = self::assess([$tap, $real, $premature], detection: $mode);
            self::assertSame('tap', $assessment->suggestion->tapEventId, $mode->value);
            self::assertSame(OnlineReviewSuggestion::SOURCE_BUTTON, $assessment->suggestion->source);
            self::assertNull($assessment->suggestion->candidateEventId);
        }

        // Detection on but no candidate counts: the tap
        self::assertSame('tap', self::assess([$tap, $early], detection: OnlineDetectionMode::On)->suggestion->tapEventId);
    }

    public function testTheDetectionErrorTheDeltaAndTheMissesBothWays(): void
    {
        $raiseMs = self::START + 47 * self::MINUTE + 12_000;
        $tapMs = $raiseMs + 1_800;
        $events = [
            // The phone said "I'm ready" with its detector watching (its set-up test passed)
            self::event('device.ready', self::START - self::MINUTE, ['detector' => ['state' => 'ready', 'view' => 'angled', 'setupTest' => 'passed', 'loadMs' => 900]]),
            // A premature raise (a piece was missing) - no tap near it, never confirmed: a false raise
            self::candidate(self::START + 40 * self::MINUTE, id: 'premature'),
            self::candidate($raiseMs, id: 'raise'),
            self::event('finish.button', $tapMs, id: 'tap'),
            // A tap the detector missed (no raise within 10 s)
            self::event('finish.button', self::START + 20 * self::MINUTE, id: 'missed-tap'),
        ];

        // Confirmed by the reviewer 400 ms after the detected moment (the hands reached the top a little later)
        $confirmed = self::assess($events, confirmed: true, confirmedMomentMs: $raiseMs + 400, detection: OnlineDetectionMode::On);
        self::assertSame('raise', $confirmed->detectedCandidate?->eventId);
        self::assertSame(400, $confirmed->detectionErrorMs);
        self::assertSame('tap', $confirmed->referenceTap?->eventId, 'the tap nearest to the confirmed moment');
        self::assertSame(1_800, $confirmed->deltaMs, 'tap − detected');
        self::assertSame('raise', $confirmed->deltaCandidate?->eventId);
        self::assertSame(1, $confirmed->missedRaises);
        self::assertSame(1, $confirmed->falseRaises);

        // Before a confirmation: the suggestion is the earliest moment - here the tap the detector missed (20 min) came
        // before every raise, so no candidate is presumed the finish; the one nearest to the last tap is the detected one
        $pending = self::assess($events, detection: OnlineDetectionMode::On);
        self::assertSame('missed-tap', $pending->suggestion->tapEventId);
        self::assertSame('raise', $pending->detectedCandidate?->eventId);
        self::assertNull($pending->detectionErrorMs, 'no error before a person confirmed');
        self::assertSame(1_800, $pending->deltaMs);
        self::assertSame(1, $pending->falseRaises, 'the premature raise');
        self::assertSame(1, $pending->missedRaises);

        // Without that early tap the suggested candidate (the first) is presumed the finish - the later raise has its tap
        $withoutEarlyTap = self::assess(array_slice($events, 0, 4), detection: OnlineDetectionMode::On);
        self::assertSame('premature', $withoutEarlyTap->suggestion->candidateEventId);
        self::assertSame('premature', $withoutEarlyTap->detectedCandidate?->eventId);
        self::assertSame(0, $withoutEarlyTap->falseRaises);
        self::assertSame(0, $withoutEarlyTap->missedRaises);

        // Shadow: the suggestion is the tap - the candidate nearest to it is the detected one
        $shadow = self::assess($events, detection: OnlineDetectionMode::Shadow);
        self::assertSame('raise', $shadow->detectedCandidate?->eventId);
        self::assertSame(1, $shadow->falseRaises, 'the premature raise: not the finish, no tap near it');

        // Confirmed far from every candidate: no detection error (a miss, not an error)
        $far = self::assess($events, confirmed: true, confirmedMomentMs: self::START + 20 * self::MINUTE, detection: OnlineDetectionMode::On);
        self::assertNull($far->detectedCandidate);
        self::assertNull($far->detectionErrorMs);
        self::assertSame(1, $far->falseRaises, 'neither raise was the finish - the later one has its tap within 10 s, the premature one is a false raise');

        // Detection off: no misses counted (there was nothing to detect them)
        $off = self::assess($events);
        self::assertNull($off->missedRaises);
        self::assertNull($off->falseRaises);
        self::assertNull($off->detectorNotRunning);
        self::assertSame(1_800, $off->deltaMs, 'candidates that came all the same are still measured');
        self::assertSame(0, $confirmed->detectorNotRunning);
    }

    public function testInOnModeACandidateAfterTheParticipantsOwnTapIsNeverSuggestedOverIt(): void
    {
        $tap = self::event('finish.button', self::START + 40 * self::MINUTE, id: 'tap');
        $after = self::candidate(self::START + 40 * self::MINUTE + 5_000, id: 'after');

        $suggestion = self::assess([$after, $tap], detection: OnlineDetectionMode::On)->suggestion;
        self::assertSame('tap', $suggestion->tapEventId, 'the tap came first');
        self::assertSame(OnlineReviewSuggestion::SOURCE_BUTTON, $suggestion->source);
        self::assertNull($suggestion->candidateEventId);
        self::assertSame(40 * 60, $suggestion->seconds);

        // The earliest of the first counting candidate and the first counting tap - a tap continued from ("Keep
        // puzzling") counts as a tap
        $kept = self::event('finish.button', self::START + 38 * self::MINUTE, id: 'kept');
        $raise = self::candidate(self::START + 39 * self::MINUTE, id: 'raise');
        self::assertSame('kept', self::assess([$kept, $raise, $tap], detection: OnlineDetectionMode::On)->suggestion->tapEventId);

        // The candidate before the tap stays the suggestion; an undone tap before it does not count
        self::assertSame('raise', self::assess([$raise, $tap], detection: OnlineDetectionMode::On)->suggestion->candidateEventId);
        $undo = self::event('finish.button.undone', self::START + 38 * self::MINUTE + 2_000, ['eventId' => 'kept']);
        self::assertSame('raise', self::assess([$kept, $undo, $raise, $tap], detection: OnlineDetectionMode::On)->suggestion->candidateEventId);

        // The same millisecond: the candidate (the review stands on it)
        $same = self::candidate(self::START + 40 * self::MINUTE, id: 'same');
        self::assertSame('same', self::assess([$tap, $same], detection: OnlineDetectionMode::On)->suggestion->candidateEventId);
    }

    public function testAFinishOnAPhoneWhoseDetectorDidNotRunIsNoMissedRaise(): void
    {
        $tapMs = self::START + 30 * self::MINUTE;
        $tap = self::event('finish.button', $tapMs, id: 'tap');
        $ready = static fn (array $detector, string $device = 'phone-a'): array => self::event('device.ready', self::START - self::MINUTE, ['detector' => $detector], device: $device);
        $watching = ['state' => 'ready', 'view' => 'angled', 'setupTest' => 'passed', 'loadMs' => 900];

        $cases = [
            'watching: a miss' => [[$ready($watching), $tap], 1, 0],
            'untested (the default view): a miss' => [[$ready([...$watching, 'setupTest' => null]), $tap], 1, 0],
            'a page loaded while the detection was off (no detector)' => [[self::event('device.ready', self::START - self::MINUTE, ['userAgent' => 'x']), $tap], 0, 1],
            'unsupported' => [[$ready(['state' => 'unsupported', 'view' => null, 'setupTest' => null, 'loadMs' => null]), $tap], 0, 1],
            'did not load' => [[$ready(['state' => 'error', 'view' => null, 'setupTest' => null, 'loadMs' => null]), $tap], 0, 1],
            'its set-up test failed' => [[$ready([...$watching, 'setupTest' => 'failed']), $tap], 0, 1],
            'skipped' => [[$ready([...$watching, 'setupTest' => 'skipped']), $tap], 0, 1],
            'turned button-only before the tap (too slow)' => [[$ready($watching), self::event('detector.changed', $tapMs - self::MINUTE, ['state' => 'ready', 'running' => false, 'reason' => 'slow', 'view' => null]), $tap], 0, 1],
            'turned button-only after the tap' => [[$ready($watching), self::event('detector.changed', $tapMs + 1_000, ['state' => 'error', 'running' => false, 'reason' => 'stalled', 'view' => null]), $tap], 1, 0],
            'still loading at "I\'m ready", watching once loaded (shadow)' => [[$ready(['state' => 'loading', 'view' => null, 'setupTest' => null, 'loadMs' => null]), self::event('detector.changed', self::START + self::MINUTE, ['state' => 'ready', 'running' => true, 'reason' => null, 'view' => 'angled']), $tap], 1, 0],
            'still loading, never loaded' => [[$ready(['state' => 'loading', 'view' => null, 'setupTest' => null, 'loadMs' => null]), $tap], 0, 1],
            'another device tapped (its own ready has none)' => [[$ready($watching), self::event('device.ready', self::START + self::MINUTE, ['userAgent' => 'x'], device: 'laptop'), self::event('finish.button', $tapMs, id: 'tap', device: 'laptop')], 0, 1],
            'a tap without any ready (nothing known)' => [[$tap], 0, 1],
        ];

        foreach ($cases as $case => [$events, $missed, $notRunning]) {
            foreach ([OnlineDetectionMode::On, OnlineDetectionMode::Shadow] as $mode) {
                $assessment = self::assess($events, detection: $mode);
                self::assertSame($missed, $assessment->missedRaises, "{$case} ({$mode->value})");
                self::assertSame($notRunning, $assessment->detectorNotRunning, "{$case} ({$mode->value})");
            }
        }

        // A tap with its candidate is no miss either way
        self::assertSame([0, 0], (static function (OnlineReviewAssessment $assessment): array {
            return [$assessment->missedRaises, $assessment->detectorNotRunning];
        })(self::assess([$tap, self::candidate($tapMs - 2_000)], detection: OnlineDetectionMode::On)));
    }

    public function testACandidateNearTheConfirmedMomentIsNeverAFalseRaise(): void
    {
        $events = [
            self::candidate(self::START + 100_000, id: 'first'),
            self::candidate(self::START + 112_000, id: 'second'),
            self::candidate(self::START + 300_000, id: 'stray'),
        ];

        // The reviewer moved the moment between them: the nearer is the detected one, the other one is that finish's too
        $moved = self::assess($events, confirmed: true, confirmedMomentMs: self::START + 106_500, detection: OnlineDetectionMode::On);
        self::assertSame('second', $moved->detectedCandidate?->eventId);
        self::assertSame(1, $moved->falseRaises, 'only the stray raise, 3 minutes away');

        // Moved more than 10 s from every candidate (detectedCandidate null): a candidate 11 s away is not that finish's
        $far = self::assess($events, confirmed: true, confirmedMomentMs: self::START + 123_500, detection: OnlineDetectionMode::On);
        self::assertNull($far->detectedCandidate);
        self::assertSame(3, $far->falseRaises);
        $near = self::assess($events, confirmed: true, confirmedMomentMs: self::START + 122_000, detection: OnlineDetectionMode::On);
        self::assertSame('second', $near->detectedCandidate?->eventId);
        self::assertSame(2, $near->falseRaises, 'the first raise is 22 s before the confirmed moment, the stray one far');
    }

    /**
     * Stream RG - the reviewer's G stored: a raise whose photo the reviewer marked as a gap is a premature raise (the
     * participant raised too early - the detector was right), a raise neither the finish, nor marked, nor near a tap a
     * false detection (the detector's own); a tap marked as a gap is a premature tap
     */
    public function testTheReviewersGapMarksSplitTheRaisesThatWereNotTheFinish(): void
    {
        $raiseMs = self::START + 47 * self::MINUTE + 12_000;
        $tapMs = $raiseMs + 1_800;
        $events = [
            self::event('device.ready', self::START - self::MINUTE, ['detector' => ['state' => 'ready', 'view' => 'angled', 'setupTest' => 'passed', 'loadMs' => 900]]),
            // Nobody raised their hands - the detector fired
            self::candidate(self::START + 30 * self::MINUTE, id: 'stray'),
            // Raised and tapped too early, a piece was missing - both marked by the reviewer
            self::candidate(self::START + 35 * self::MINUTE, id: 'with-tap'),
            self::event('finish.button', self::START + 35 * self::MINUTE + 1_000, id: 'early-tap'),
            // Raised too early, no tap - marked
            self::candidate(self::START + 40 * self::MINUTE, id: 'premature'),
            // The finish
            self::candidate($raiseMs, id: 'raise'),
            self::event('finish.button', $tapMs, id: 'tap'),
        ];
        $marked = ['with-tap', 'early-tap', 'PREMATURE'];

        $confirmed = self::assess($events, confirmed: true, confirmedMomentMs: $raiseMs + 400, detection: OnlineDetectionMode::On, gaps: $marked);
        self::assertSame('raise', $confirmed->detectedCandidate?->eventId);
        self::assertSame(400, $confirmed->detectionErrorMs, 'the finish\'s raise is not marked - its error is as without marks');
        self::assertSame(2, $confirmed->prematureRaises, 'the marked raises - a tap near one or not');
        self::assertSame(1, $confirmed->falseDetections, 'the stray one');
        self::assertSame(2, $confirmed->falseRaises, 'false detections + premature raises without a tap near (stray, premature)');
        self::assertSame(1, $confirmed->prematureTaps);
        self::assertSame(0, $confirmed->missedRaises);

        // Without marks: no premature raise, every false raise is a false detection, no premature tap
        $unmarked = self::assess($events, confirmed: true, confirmedMomentMs: $raiseMs + 400, detection: OnlineDetectionMode::On);
        self::assertSame([0, 2, 2, 0], [$unmarked->prematureRaises, $unmarked->falseDetections, $unmarked->falseRaises, $unmarked->prematureTaps]);

        // Before a confirmation the suggested raise (the stray one, the earliest moment) is presumed the finish ...
        $pending = self::assess($events, detection: OnlineDetectionMode::On, gaps: $marked);
        self::assertSame('stray', $pending->suggestion->candidateEventId);
        self::assertSame([2, 0], [$pending->prematureRaises, $pending->falseDetections]);
        // ... unless it is marked: then it is never suggested - the earliest unmarked moment is (here the early tap)
        $suggestedMarked = self::assess($events, detection: OnlineDetectionMode::On, gaps: [...$marked, 'stray']);
        self::assertNull($suggestedMarked->suggestion->candidateEventId);
        self::assertSame('early-tap', $suggestedMarked->suggestion->tapEventId);
        self::assertSame([3, 0], [$suggestedMarked->prematureRaises, $suggestedMarked->falseDetections]);

        // A marked raise within 10 s of the confirmed moment is never that finish's (the mark overrides the window): no
        // detection, the tap is a missed raise
        $markedFinish = self::assess($events, confirmed: true, confirmedMomentMs: $raiseMs + 400, detection: OnlineDetectionMode::On, gaps: [...$marked, 'raise']);
        self::assertNull($markedFinish->detectedCandidate);
        self::assertNull($markedFinish->detectionErrorMs);
        self::assertNull($markedFinish->deltaMs);
        self::assertSame(3, $markedFinish->prematureRaises);
        self::assertSame(1, $markedFinish->missedRaises);

        // A marked tap the reviewer confirmed at its own moment is the finish all the same
        $tapConfirmed = self::assess($events, confirmed: true, confirmedMomentMs: $tapMs, detection: OnlineDetectionMode::On, gaps: [...$marked, 'tap']);
        self::assertSame(1, $tapConfirmed->prematureTaps);

        // Shadow: the same split (the report has every raise)
        $shadow = self::assess($events, confirmed: true, confirmedMomentMs: $tapMs, detection: OnlineDetectionMode::Shadow, gaps: $marked);
        self::assertSame([2, 1, 1], [$shadow->prematureRaises, $shadow->falseDetections, $shadow->prematureTaps]);

        // Detection off: no raises counted - the premature taps are
        $off = self::assess($events, confirmed: true, confirmedMomentMs: $tapMs, gaps: $marked);
        self::assertSame([null, null, 1], [$off->prematureRaises, $off->falseDetections, $off->prematureTaps]);

        // Without a tap that counts there is nothing to count
        self::assertNull(self::assess([self::candidate(self::START + self::MINUTE, id: 'only')], detection: OnlineDetectionMode::On, gaps: ['only'])->prematureTaps);
    }

    /**
     * A raise the reviewer marked as a gap is never a finish's raise - the mark overrides the ±10 s window (the
     * orchestrator's decision, stream RG): the detected moment, the detection error and the tap delay come from the
     * next unmarked raise in the window, and a finish whose only raise is marked has no detection
     */
    public function testAMarkedRaiseIsNeverTheFinishsRaise(): void
    {
        $raiseMs = self::START + 30 * self::MINUTE;
        $ready = self::event('device.ready', self::START - self::MINUTE, ['detector' => ['state' => 'ready', 'view' => 'angled', 'setupTest' => 'passed', 'loadMs' => 900]]);
        $events = [
            $ready,
            // Raised with a piece missing, placed it, raised again 4 s later; tapped in between
            self::candidate($raiseMs, id: 'early'),
            self::event('finish.button', $raiseMs + 1_500, id: 'tap'),
            self::candidate($raiseMs + 4_000, id: 'again'),
        ];
        $confirmedAt = $raiseMs + 1_000;

        // Without marks the nearer raise is the finish's: +1.0 s error, tap delay +1.5 s
        $unmarked = self::assess($events, confirmed: true, confirmedMomentMs: $confirmedAt, detection: OnlineDetectionMode::On);
        self::assertSame('early', $unmarked->detectedCandidate?->eventId);
        self::assertSame([1_000, 'early', 1_500, 0], [$unmarked->detectionErrorMs, $unmarked->deltaCandidate?->eventId, $unmarked->deltaMs, $unmarked->prematureRaises]);

        // Marked: the next unmarked raise inside the window is the finish's - error −3.0 s, tap delay −2.5 s
        $marked = self::assess($events, confirmed: true, confirmedMomentMs: $confirmedAt, detection: OnlineDetectionMode::On, gaps: ['early']);
        self::assertSame('again', $marked->detectedCandidate?->eventId);
        self::assertSame([-3_000, 'again', -2_500], [$marked->detectionErrorMs, $marked->deltaCandidate?->eventId, $marked->deltaMs]);
        self::assertSame([1, 0, 0], [$marked->prematureRaises, $marked->falseDetections, $marked->missedRaises]);

        // The finish's only raise marked: no detection - a missed raise on a phone whose detector watched ...
        $only = [$ready, self::candidate($raiseMs, id: 'early'), self::event('finish.button', $raiseMs + 6_000, id: 'tap')];
        $missed = self::assess($only, confirmed: true, confirmedMomentMs: $raiseMs + 6_000, detection: OnlineDetectionMode::On, gaps: ['early']);
        self::assertNull($missed->detectedCandidate);
        self::assertNull($missed->detectionErrorMs);
        self::assertNull($missed->deltaCandidate);
        self::assertSame([1, 0, 1, 0], [$missed->missedRaises, $missed->detectorNotRunning, $missed->prematureRaises, $missed->falseDetections]);
        // ... (without the mark the raise 6 s before was that finish's)
        self::assertSame([0, 'early'], [self::assess($only, confirmed: true, confirmedMomentMs: $raiseMs + 6_000, detection: OnlineDetectionMode::On)->missedRaises, self::assess($only, confirmed: true, confirmedMomentMs: $raiseMs + 6_000, detection: OnlineDetectionMode::On)->detectedCandidate?->eventId]);
        // ... and "detector not running" on a phone whose detector did not
        $notRunning = [self::event('device.ready', self::START - self::MINUTE, ['detector' => ['state' => 'unsupported', 'view' => null, 'setupTest' => null, 'loadMs' => null]]), ...array_slice($only, 1)];
        $withoutDetector = self::assess($notRunning, confirmed: true, confirmedMomentMs: $raiseMs + 6_000, detection: OnlineDetectionMode::On, gaps: ['early']);
        self::assertSame([0, 1], [$withoutDetector->missedRaises, $withoutDetector->detectorNotRunning]);

        // A tap marked as a gap is matched against its own (marked) raise: no missed raise for it
        $prematureTap = self::assess($only, detection: OnlineDetectionMode::Shadow, gaps: ['early', 'tap']);
        self::assertSame([0, 1], [$prematureTap->missedRaises, $prematureTap->prematureTaps]);
    }

    /**
     * `on`: the suggestion is the first raise the reviewer did not mark as a gap - and without marks exactly as before
     */
    public function testTheSuggestionSkipsARaiseMarkedAsAGap(): void
    {
        $first = self::candidate(self::START + 20 * self::MINUTE, id: 'first');
        $second = self::candidate(self::START + 21 * self::MINUTE, id: 'second');
        $tap = self::event('finish.button', self::START + 21 * self::MINUTE + 2_000, id: 'tap');

        self::assertSame('first', self::assess([$first, $second, $tap], detection: OnlineDetectionMode::On)->suggestion->candidateEventId, 'without marks: the first raise');
        self::assertSame('first', self::assess([$first, $second, $tap], detection: OnlineDetectionMode::On, gaps: [])->suggestion->candidateEventId);

        $marked = self::assess([$first, $second, $tap], detection: OnlineDetectionMode::On, gaps: ['FIRST']);
        self::assertSame('second', $marked->suggestion->candidateEventId);
        self::assertSame((21 * 60), $marked->suggestion->seconds);
        self::assertSame('second', $marked->detectedCandidate?->eventId, 'the presumed finish follows the suggestion');

        // Every raise marked: the tap
        $allMarked = self::assess([$first, $second, $tap], detection: OnlineDetectionMode::On, gaps: ['first', 'second']);
        self::assertNull($allMarked->suggestion->candidateEventId);
        self::assertSame('tap', $allMarked->suggestion->tapEventId);

        // `shadow` / `off`: the tap as ever
        self::assertSame('tap', self::assess([$first, $second, $tap], detection: OnlineDetectionMode::Shadow, gaps: ['first'])->suggestion->tapEventId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{online_entry_id: string, id: string, type: string, device_id: string, client_ms: int, received_at_ms: int, payload: array<string, mixed>, heard_meanwhile: bool}
     */
    private static function candidate(int $clientMs, null|string $id = null, array $payload = []): array
    {
        return self::event('finish.candidate', $clientMs, $payload, id: $id);
    }

    /**
     * @param list<array{online_entry_id: string, id: string, type: string, device_id: string, client_ms: int, received_at_ms: int, payload: array<string, mixed>, heard_meanwhile: bool}> $events
     * @param list<string> $storedFlags
     * @param list<string> $gaps
     */
    private static function assess(
        array $events,
        null|RoundClock $clock = null,
        null|int $nowMs = null,
        bool $confirmed = false,
        null|int $confirmedMomentMs = null,
        null|string $reportedMeasure = null,
        null|int $reportedPieces = null,
        null|int $piecesTotal = 3000,
        array $storedFlags = [],
        OnlineDetectionMode $detection = OnlineDetectionMode::Off,
        array $gaps = [],
    ): OnlineReviewAssessment {
        $nowMs ??= self::START + 59 * self::MINUTE;

        return OnlineReviewAssessment::of(
            clock: $clock ?? RoundClock::fromStopwatch('running', self::moment(self::START), null, 60, self::moment($nowMs)),
            nowMs: $nowMs,
            confirmed: $confirmed,
            confirmedMomentMs: $confirmedMomentMs,
            reportedMeasure: $reportedMeasure,
            reportedPieces: $reportedPieces,
            piecesTotal: $piecesTotal,
            events: $events,
            storedFlags: $storedFlags,
            detection: $detection,
            gaps: $gaps,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{online_entry_id: string, id: string, type: string, device_id: string, client_ms: int, received_at_ms: int, payload: array<string, mixed>, heard_meanwhile: bool}
     */
    private static function event(
        string $type,
        int $clientMs,
        array $payload = [],
        null|int $received = null,
        string $device = 'phone-a',
        bool $heard = false,
        null|string $id = null,
    ): array {
        self::$sequence++;

        return [
            'online_entry_id' => 'entry',
            'id' => $id ?? sprintf('event-%04d', self::$sequence),
            'type' => $type,
            'device_id' => $device,
            'client_ms' => $clientMs,
            'received_at_ms' => $received ?? $clientMs + 300,
            'payload' => $payload,
            'heard_meanwhile' => $heard,
        ];
    }

    private static function moment(int $ms): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . intdiv($ms, 1000));
    }

    /**
     * @return array<string, null|int> code => count
     */
    private static function flagCounts(OnlineReviewAssessment $assessment): array
    {
        $counts = [];

        foreach ($assessment->flags as $flag) {
            $counts[$flag->code] = $flag->count;
        }

        return $counts;
    }

    private static function flag(OnlineReviewAssessment $assessment, string $code): null|OnlineReviewFlag
    {
        foreach ($assessment->flags as $flag) {
            if ($flag->code === $code) {
                return $flag;
            }
        }

        return null;
    }
}
