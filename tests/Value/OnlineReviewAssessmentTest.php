<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\OnlineReviewFlag;
use SpeedPuzzling\Web\Results\OnlineReviewSuggestion;
use SpeedPuzzling\Web\Results\OnlineReviewTap;
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
