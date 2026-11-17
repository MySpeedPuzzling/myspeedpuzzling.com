<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\CompetitionToolkit\TimeProposalTiming;
use SpeedPuzzling\Web\Value\CompetitorHeartbeatRecord;
use SpeedPuzzling\Web\Value\TimeProposalVerdict;

/**
 * The timing verdict of a competitor's tap (reliability hardening T2, competitor-pages.md "Timing you can trust"):
 * the tap is trusted unless a clean heartbeat of the same phone - built after the tap, received well before the
 * proposal - says the phone was online and held no tap.
 */
final class TimeProposalTimingTest extends TestCase
{
    private const int TAP = 1_000_000_000;

    /**
     * @return iterable<string, array{int, null|CompetitorHeartbeatRecord, TimeProposalVerdict, null|int}>
     */
    public static function provideCases(): iterable
    {
        // [received after the tap, the heartbeat record, verdict, lastHeardAtMs]
        yield 'online - received at once' => [300, null, TimeProposalVerdict::OnTime, null];
        yield 'exactly 5 s is on time' => [5000, null, TimeProposalVerdict::OnTime, null];
        yield 'on time even when a clean beat came after the tap' => [4000, self::record(clean: 3000, cleanHeard: 3100, last: 3100), TimeProposalVerdict::OnTime, self::TAP + 3100];
        yield 'late without any heartbeat - an old page or an unreachable phone' => [42_000, null, TimeProposalVerdict::Offline, null];
        yield 'late, the last clean beat before the tap (offline since)' => [42_000, self::record(clean: -10_000, cleanHeard: -9_900, last: 30_000), TimeProposalVerdict::Offline, self::TAP + 30_000];
        yield 'late, beats after the tap reported the unsent tap only' => [42_000, self::record(clean: -10_000, cleanHeard: -9_900, last: 41_000), TimeProposalVerdict::Offline, self::TAP + 41_000];
        yield 'online but silent: a clean beat 15 s after the tap, the proposal 25 s later' => [40_000, self::record(clean: 15_000, cleanHeard: 15_050, last: 30_000), TimeProposalVerdict::Suspicious, self::TAP + 15_050];
        yield 'a clean beat within 2 s after the tap may have been on its way' => [40_000, self::record(clean: 2000, cleanHeard: 2050, last: 2050), TimeProposalVerdict::Offline, self::TAP + 2050];
        yield 'the clean beat and the proposal at once (a reconnect sends both)' => [40_000, self::record(clean: 36_000, cleanHeard: 36_000, last: 36_000), TimeProposalVerdict::Offline, self::TAP + 36_000];
        yield 'a clean beat heard after the proposal is no evidence' => [40_000, self::record(clean: 20_000, cleanHeard: 41_000, last: 41_000), TimeProposalVerdict::Offline, self::TAP + 41_000];
    }

    #[DataProvider('provideCases')]
    public function testTheVerdict(int $receivedAfterMs, null|CompetitorHeartbeatRecord $record, TimeProposalVerdict $verdict, null|int $lastHeardAtMs): void
    {
        $timing = TimeProposalTiming::judge(self::TAP, self::TAP + $receivedAfterMs, $record, 10.0, 80.0, 4000, self::TAP + $receivedAfterMs);

        self::assertSame($verdict, $timing->verdict);
        self::assertSame($lastHeardAtMs, $timing->lastHeardAtMs);
        self::assertFalse($timing->clockUncertain);
    }

    /**
     * @return iterable<string, array{null|float, null|float, null|int, null|int, null|CompetitorHeartbeatRecord, bool}>
     */
    public static function provideClocks(): iterable
    {
        // [offsetMs, rttMs, sample age, sentAt − received, record, uncertain]
        yield 'a fresh sample' => [10.0, 80.0, 60_000, 0, null, false];
        yield 'no measured sample - only the page hint' => [10.0, null, null, 0, null, true];
        yield 'a sample older than 30 min' => [10.0, 80.0, 31 * 60_000, 0, null, true];
        yield 'the old page sends no sample age' => [10.0, 80.0, null, null, null, false];
        yield 'the offset jumped since the last heartbeat' => [3_000.0, 80.0, 1000, 0, new CompetitorHeartbeatRecord(lastHeardAtMs: self::TAP, lastOffsetMs: 500.0), true];
        yield 'the offset moved a little' => [600.0, 80.0, 1000, 0, new CompetitorHeartbeatRecord(lastHeardAtMs: self::TAP, lastOffsetMs: 500.0), false];
        yield 'the phone claims to send 3 s after our receipt' => [10.0, 80.0, 1000, 3000, null, true];
        yield 'the phone sent 12 s before our receipt' => [10.0, 80.0, 1000, -12_000, null, true];
        yield 'a slow network, 4 s' => [10.0, 80.0, 1000, -4000, null, false];
    }

    #[DataProvider('provideClocks')]
    public function testTheClockInDoubtIsANoteNeverAnotherVerdict(null|float $offsetMs, null|float $rttMs, null|int $sampleAgeMs, null|int $sentAfterReceiptMs, null|CompetitorHeartbeatRecord $record, bool $uncertain): void
    {
        $received = self::TAP + 1000;
        $timing = TimeProposalTiming::judge(self::TAP, $received, $record, $offsetMs, $rttMs, $sampleAgeMs, $sentAfterReceiptMs === null ? null : $received + $sentAfterReceiptMs);

        self::assertSame($uncertain, $timing->clockUncertain);
        self::assertSame(TimeProposalVerdict::OnTime, $timing->verdict);
    }

    private static function record(int $clean, int $cleanHeard, int $last): CompetitorHeartbeatRecord
    {
        return new CompetitorHeartbeatRecord(
            lastHeardAtMs: self::TAP + $last,
            lastOffsetMs: 10.0,
            cleanAtMs: self::TAP + $clean,
            cleanHeardAtMs: self::TAP + $cleanHeard,
        );
    }
}
