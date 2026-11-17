<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Competition toolkit P1F - which start a proposal is measured against:
 * - the link must be older than the round's FIRST start (a resume moves the stored start forward by the pause, so a
 *   link made during a long pause would otherwise pass); a scheduled start counts from its moment; a reset starts over;
 * - a round the judge stopped was running at every tap up to the stop: a tap before it arriving after it counts.
 *
 * The stopwatch is driven through the entity's own methods (as the stopwatch handlers do), at chosen moments.
 */
final class TimeProposalStartMomentTest extends KernelTestCase
{
    use TimeProposalScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        self::runningRounds();
    }

    public function testALinkMadeDuringAPauseIsRefusedAfterTheResume(): void
    {
        // Started 60 min ago, paused 50 min ago, resumed 10 min ago: the stored start moves to 20 min ago
        self::onPairsRound(static function (CompetitionRound $round): void {
            $round->resetStopwatch(); // runningRounds() started it in SQL
            $round->startStopwatch(self::ago(60));
            $round->stopStopwatch(self::ago(50));
            $round->startStopwatch(self::ago(10));
        });

        $round = self::pairsRound();
        self::assertEquals(self::ago(20), $round->stopwatchStartedAt);
        self::assertEquals(self::ago(60), $round->stopwatchFirstStartedAt);
        self::assertEquals(self::ago(60), $round->stopwatchOriginalStartAt());

        // Eva's row linked during the pause - after the round's first start, before the moved one
        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, self::ago(30));
        self::assertSame('linked_after_start', self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS)->reason);

        // Linked before the first start: theirs
        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, self::ago(61));
        self::assertSame(TimeProposalAnswer::PROPOSED, self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS)->status);
    }

    public function testTheLinkIsMeasuredAgainstAScheduledStartAlsoAfterAPause(): void
    {
        // Scheduled (made 70 min ago) for 60 min ago, fired, paused 50 min ago, resumed 10 min ago
        self::onPairsRound(static function (CompetitionRound $round): void {
            $round->resetStopwatch(); // runningRounds() started it in SQL
            $round->scheduleStopwatchStart(self::ago(60), self::ago(70));
        });
        self::assertEquals(self::ago(60), self::pairsRound()->stopwatchFirstStartedAt);

        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, self::ago(55));
        self::assertSame('linked_after_start', self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS)->reason);

        self::onPairsRound(static function (CompetitionRound $round): void {
            $round->stopStopwatch(self::ago(50));
            $round->startStopwatch(self::ago(10));
        });
        self::assertEquals(self::ago(60), self::pairsRound()->stopwatchFirstStartedAt);

        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, self::ago(30));
        self::assertSame('linked_after_start', self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS)->reason);
    }

    public function testAResetStartsOverAndTheNewStartCounts(): void
    {
        self::onPairsRound(static function (CompetitionRound $round): void {
            $round->resetStopwatch(); // runningRounds() started it in SQL
            $round->startStopwatch(self::ago(60));
            $round->resetStopwatch();
        });
        self::assertNull(self::pairsRound()->stopwatchFirstStartedAt);

        self::onPairsRound(static function (CompetitionRound $round): void {
            $round->startStopwatch(self::ago(10));
        });
        self::assertEquals(self::ago(10), self::pairsRound()->stopwatchFirstStartedAt);

        // Linked after the first run's start, before the new run's: theirs
        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, self::ago(30));
        self::assertSame(TimeProposalAnswer::PROPOSED, self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS)->status);
    }

    public function testACancelledCountdownLeavesNoStart(): void
    {
        self::onPairsRound(static function (CompetitionRound $round): void {
            $round->resetStopwatch(); // runningRounds() started it in SQL
            $round->scheduleStopwatchStart(TestClock::now()->modify('+5 minutes'), TestClock::now());
            $round->cancelScheduledStart(TestClock::now());
        });

        self::assertNull(self::pairsRound()->stopwatchFirstStartedAt);
        self::assertNull(self::pairsRound()->stopwatchOriginalStartAt());
    }

    public function testARowFromBeforeTheColumnFallsBackToTheStoredStart(): void
    {
        // runningRounds() wrote the start in SQL only - no first start, as on rows from before P1F
        self::assertNull(self::pairsRound()->stopwatchFirstStartedAt);
        self::assertEquals(self::ago(10), self::pairsRound()->stopwatchOriginalStartAt());

        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, self::ago(5));
        self::assertSame('linked_after_start', self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS)->reason);
    }

    public function testAWithdrawalFollowsTheFirstStartToo(): void
    {
        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, self::ago(70));
        self::onPairsRound(static function (CompetitionRound $round): void {
            $round->resetStopwatch();
            $round->startStopwatch(self::ago(60));
            $round->stopStopwatch(self::ago(50));
            $round->startStopwatch(self::ago(10));
        });
        $proposal = self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS);
        assert($proposal->proposalId !== null);

        // Somebody else takes Eva's row during the pause (after the first start): may not withdraw the pair's proposal
        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_WITH_FAVORITES, self::ago(30));
        self::assertSame('linked_after_start', self::withdraw($proposal->proposalId, PlayerFixture::PLAYER_WITH_FAVORITES)->reason);
        self::assertSame('pending', self::proposalRow($proposal->proposalId)['status']);
    }

    public function testATapBeforeTheStopArrivingAfterItCounts(): void
    {
        // Started 20 min ago, the judge stopped 1 min ago; tapped 2 min ago, received now
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, self::ago(20), 'stopped', self::ago(1));

        $answer = self::propose(self::ANNA_FINAL, tappedAgoMs: 120_000);

        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        assert($answer->proposalId !== null);
        $row = self::proposalRow($answer->proposalId);
        self::assertSame(18 * 60, $row['proposed_seconds']);
        self::assertSame(120_000, $row['late_by_ms']); // flagged late for the judge
    }

    public function testATapAfterTheStopIsRefused(): void
    {
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, self::ago(20), 'stopped', self::ago(1));

        self::assertSame('not_running', self::propose(self::ANNA_FINAL, tappedAgoMs: 30_000)->reason);
        self::assertSame(0, self::proposalCount());
    }

    public function testATapBeforeTheStopReceivedMoreThanHalfAnHourAfterItIsTooLate(): void
    {
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, self::ago(60), 'stopped', self::ago(31));

        self::assertSame('too_late', self::propose(self::ANNA_FINAL, tappedAgoMs: 40 * 60_000)->reason);

        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, self::ago(60), 'stopped', self::ago(29));
        self::assertSame(TimeProposalAnswer::PROPOSED, self::propose(self::ANNA_FINAL, tappedAgoMs: 40 * 60_000)->status);
    }

    private static function ago(int $minutes): DateTimeImmutable
    {
        return TestClock::now()->modify(sprintf('-%d minutes', $minutes));
    }

    /**
     * @param callable(CompetitionRound): void $change
     */
    private static function onPairsRound(callable $change): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $change(self::getContainer()->get(CompetitionRoundRepository::class)->get(OfficialResultsFixture::ROUND_PAIRS));
        $entityManager->flush();
        $entityManager->clear();
    }

    private static function pairsRound(): CompetitionRound
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(CompetitionRoundRepository::class)->get(OfficialResultsFixture::ROUND_PAIRS);
    }
}
