<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals;

use SpeedPuzzling\Web\Query\GetTimeProposals;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Results\TimeProposalView;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\CompetitorHeartbeatRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The timing verdict stored with a proposal and shown to the judges (reliability hardening T2,
 * competitor-pages.md "Timing you can trust"): the handler judges the evidence the controller passes in the message.
 * The Final runs for 10 minutes; Anna's tap is `tappedAgoMs` before the (frozen) receipt.
 */
final class ProposalTimingVerdictTest extends KernelTestCase
{
    use TimeProposalScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        self::runningRounds();
    }

    public function testAnOnlineTapIsOnTime(): void
    {
        $row = self::row(self::propose(self::ANNA_FINAL, tappedAgoMs: 800));

        self::assertSame('on_time', $row['timing_verdict']);
        self::assertFalse($row['clock_uncertain']);
        self::assertFalse($row['queued_offline']);
        self::assertSame(1, $row['send_attempts']);
        self::assertSame(4000, $row['offset_sample_age_ms']);
    }

    public function testAnHonestOfflineTapKeepsItsTime(): void
    {
        // Tapped 42 s ago, offline since a clean beat 10 s before the tap; the beats after said "unsent tap"
        $heartbeat = new CompetitorHeartbeatRecord(lastHeardAtMs: self::ago(1000), lastOffsetMs: 12.0, cleanAtMs: self::ago(52_000), cleanHeardAtMs: self::ago(52_000));
        $answer = self::propose(self::ANNA_FINAL, tappedAgoMs: 42_000, heartbeat: $heartbeat, queuedOffline: true, attempts: 6);
        $row = self::row($answer);

        self::assertSame('offline', $row['timing_verdict']);
        self::assertEquals(self::ago(1000), $row['last_heard_at_ms']);
        self::assertTrue($row['queued_offline']);
        self::assertSame(6, $row['send_attempts']);

        $view = self::view($answer);
        self::assertTrue($view->late, 'late stays for compatibility');
        $json = $view->jsonSerialize();
        self::assertSame(['verdict' => 'offline', 'lastHeardAtMs' => self::ago(1000), 'clockUncertain' => false, 'queuedOffline' => true, 'attempts' => 6], $json['timing']);
        // Never on the competitor's phone
        self::assertArrayNotHasKey('timing', $view->forCompetitor()->jsonSerialize());
    }

    public function testNoHeartbeatAtAllTrustsTheTap(): void
    {
        self::assertSame('offline', self::row(self::propose(self::ANNA_FINAL, tappedAgoMs: 30_000))['timing_verdict']);

        // A pair member's tap the same way
        $pair = self::propose(self::UNNAMED_PAIR, playerId: \SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture::PLAYER_REGULAR, tappedAgoMs: 30_000, roundId: OfficialResultsFixture::ROUND_PAIRS);
        self::assertSame('offline', self::row($pair)['timing_verdict']);
    }

    public function testAPhoneThatWasOnlineWithNothingUnsentIsSuspicious(): void
    {
        // Tapped 40 s ago; a clean beat built 25 s ago (15 s after the tap) reached us; the proposal 25 s later
        $heartbeat = new CompetitorHeartbeatRecord(lastHeardAtMs: self::ago(10_000), lastOffsetMs: 12.0, cleanAtMs: self::ago(25_000), cleanHeardAtMs: self::ago(24_900));
        $answer = self::propose(self::ANNA_FINAL, tappedAgoMs: 40_000, heartbeat: $heartbeat);
        $row = self::row($answer);

        self::assertSame('suspicious', $row['timing_verdict']);
        self::assertEquals(self::ago(24_900), $row['last_heard_at_ms']);
        self::assertSame(560, $row['proposed_seconds']);
        self::assertSame(600, $row['receipt_seconds']);
        $timing = self::view($answer)->jsonSerialize()['timing'];
        self::assertIsArray($timing);
        self::assertSame('suspicious', $timing['verdict']);
    }

    public function testAnOffsetJumpIsANoteNotARefusal(): void
    {
        $jump = new CompetitorHeartbeatRecord(lastHeardAtMs: self::ago(3000), lastOffsetMs: -4000.0);
        $answer = self::propose(self::ANNA_FINAL, tappedAgoMs: 1000, heartbeat: $jump);
        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        self::assertTrue(self::row($answer)['clock_uncertain']);
        assert($answer->proposalId !== null);
        self::withdraw($answer->proposalId);

        // An old clock sample alone is no evidence - kept as a diagnostic only
        $stale = self::propose(self::ANNA_FINAL, tappedAgoMs: 1000, offsetSampleAgeMs: 45 * 60_000);
        self::assertSame('on_time', self::row($stale)['timing_verdict']);
        self::assertFalse(self::row($stale)['clock_uncertain']);
        self::assertSame(45 * 60_000, self::row($stale)['offset_sample_age_ms']);
    }

    public function testAResendAfterAReloadKeepsTheOriginalTapAndVerdict(): void
    {
        $proposalId = self::newProposalId(-40_000);
        $first = self::propose(self::ANNA_FINAL, proposalId: $proposalId, tappedAgoMs: 40_000, queuedOffline: true, attempts: 3);
        self::assertSame(TimeProposalAnswer::PROPOSED, $first->status);

        // The reloaded page sends the same item again (same id, the original tap) - with a clean beat meanwhile
        $clean = new CompetitorHeartbeatRecord(lastHeardAtMs: self::ago(0), cleanAtMs: self::ago(0), cleanHeardAtMs: self::ago(0));
        $again = self::propose(self::ANNA_FINAL, proposalId: $proposalId, tappedAgoMs: 40_000, heartbeat: $clean, attempts: 4);

        self::assertSame(TimeProposalAnswer::KNOWN, $again->status);
        $row = self::row($first);
        self::assertSame('offline', $row['timing_verdict']);
        self::assertSame(3, $row['send_attempts']);
        self::assertSame(560, $row['proposed_seconds']);
    }

    public function testASecondTapWhileOneIsPendingIsNoNewProposal(): void
    {
        $first = self::propose(self::ANNA_FINAL, tappedAgoMs: 20_000);
        $second = self::propose(self::ANNA_FINAL, tappedAgoMs: 1000);

        self::assertSame('already_proposed', $second->reason);
        self::assertSame($first->proposalId, $second->proposalId);
        self::assertEquals(1, self::db()->fetchOne('SELECT COUNT(*) FROM competition_time_proposal'));
    }

    public function testAProposalFromBeforeT2HasNoTiming(): void
    {
        $answer = self::propose(self::ANNA_FINAL, tappedAgoMs: 9000);
        self::db()->executeStatement('UPDATE competition_time_proposal SET timing_verdict = NULL WHERE id = :id', ['id' => $answer->proposalId]);

        $json = self::view($answer)->jsonSerialize();
        self::assertNull($json['timing']);
        self::assertTrue($json['late']);
    }

    private static function ago(int $ms): int
    {
        return TestClock::milliseconds() - $ms;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(TimeProposalAnswer $answer): array
    {
        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        assert($answer->proposalId !== null);

        return self::proposalRow($answer->proposalId);
    }

    private static function view(TimeProposalAnswer $answer): TimeProposalView
    {
        assert($answer->proposalId !== null);
        $views = self::getContainer()->get(GetTimeProposals::class)->byIds(OfficialResultsFixture::ROUND_FINAL, [$answer->proposalId]);
        self::assertCount(1, $views);

        return $views[0];
    }
}
