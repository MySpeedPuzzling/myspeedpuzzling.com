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

        // Before a confirmation: the suggested candidate (the first) is presumed the finish - the later raise has its tap
        $pending = self::assess($events, detection: OnlineDetectionMode::On);
        self::assertSame('premature', $pending->detectedCandidate?->eventId);
        self::assertNull($pending->detectionErrorMs, 'no error before a person confirmed');
        self::assertSame(1_800, $pending->deltaMs);
        self::assertSame(0, $pending->falseRaises);
        self::assertSame(1, $pending->missedRaises);

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
        self::assertSame(1_800, $off->deltaMs, 'candidates that came all the same are still measured');
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
