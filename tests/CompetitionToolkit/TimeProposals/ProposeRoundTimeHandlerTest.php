<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\ResetRoundStopwatch;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Results\TimeProposalsSuperseded;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Proposing a time and taking it back (competition toolkit P1, toolkit/B-qr-competitor-page.md §5.3, §9 and
 * R-review-and-plan.md §2.4): who may, when, and what is stored.
 */
final class ProposeRoundTimeHandlerTest extends KernelTestCase
{
    use TimeProposalScenario;

    private const string SHARKS = 'team:' . OfficialResultsFixture::TEAM_SHARKS;

    protected function setUp(): void
    {
        self::bootKernel();
        self::runningRounds();
    }

    public function testTheLinkedCompetitorProposesTheirTimeAsAPendingProposal(): void
    {
        $proposalId = self::newProposalId();
        $answer = self::propose(self::ANNA_FINAL, proposalId: $proposalId, tappedAgoMs: 2000);

        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        self::assertSame($proposalId, $answer->proposalId);
        self::assertTrue($answer->changed());

        $row = self::proposalRow($proposalId);
        self::assertSame('pending', $row['status']);
        self::assertSame(OfficialResultsFixture::ENTRY_FINAL_ANNA, $row['participant_round_id']);
        self::assertNull($row['team_id']);
        self::assertSame(OfficialResultsFixture::PARTICIPANT_ANNA, $row['proposed_by_participant_id']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['proposed_by_player_id']);
        // Started 10 minutes ago, tapped 2 s ago, received now
        self::assertSame(598, $row['proposed_seconds']);
        self::assertSame(600, $row['receipt_seconds']);
        self::assertSame(2000, $row['late_by_ms']);
        self::assertFalse($row['clock_changed']);
        self::assertEquals((TestClock::timestamp() - 600) * 1000, $row['started_at_ms']);
        self::assertNull($row['decided_at']);
    }

    public function testAMemberOfAPairProposesForThePair(): void
    {
        $answer = self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR, roundId: OfficialResultsFixture::ROUND_PAIRS);

        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        assert($answer->proposalId !== null);
        $row = self::proposalRow($answer->proposalId);
        self::assertSame(OfficialResultsFixture::TEAM_UNNAMED, $row['team_id']);
        self::assertSame(OfficialResultsFixture::PARTICIPANT_FILIP, $row['proposed_by_participant_id']);
    }

    public function testAResendIsAnsweredNeverStoredTwice(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId);
        $again = self::propose(self::ANNA_FINAL, proposalId: $proposalId, tappedAgoMs: 1000);

        self::assertSame(TimeProposalAnswer::KNOWN, $again->status);
        self::assertSame($proposalId, $again->proposalId);
        self::assertFalse($again->changed());
        self::assertSame(1, self::proposalCount());
        self::assertSame(598, self::proposalRow($proposalId)['proposed_seconds']);
    }

    public function testAKnownIdReplayedByAnotherSenderOrForAnotherEntryIsRefusedWithoutItsState(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId);

        // Another player replaying the id against the same entry
        $foreignSender = self::propose(self::ANNA_FINAL, PlayerFixture::PLAYER_REGULAR, proposalId: $proposalId);
        self::assertSame(TimeProposalAnswer::REFUSED, $foreignSender->status);
        self::assertSame('invalid_proposal_id', $foreignSender->reason);
        self::assertNull($foreignSender->proposalId);

        // Filip's own entry with Anna's id
        $foreignEntry = self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR, proposalId: $proposalId, roundId: OfficialResultsFixture::ROUND_PAIRS);
        self::assertSame('invalid_proposal_id', $foreignEntry->reason);
        self::assertNull($foreignEntry->proposalId);

        self::assertSame(1, self::proposalCount());
    }

    public function testTheIdMustBeAFreshUuidV7(): void
    {
        self::assertSame('invalid_proposal_id', self::propose(self::ANNA_FINAL, proposalId: Uuid::uuid4()->toString())->reason);
        self::assertSame('invalid_proposal_id', self::propose(self::ANNA_FINAL, proposalId: self::newProposalId(-2 * 86_400_000))->reason);
        self::assertSame(0, self::proposalCount());
    }

    public function testNobodyProposesForSomebodyElse(): void
    {
        $answer = self::propose(self::ANNA_FINAL, PlayerFixture::PLAYER_REGULAR);

        self::assertSame(TimeProposalAnswer::REFUSED, $answer->status);
        self::assertSame('not_your_entry', $answer->reason);
        self::assertSame(0, self::proposalCount());
    }

    /**
     * R §2.4 item 1: anybody may connect to an unconnected participant - a link made after the round's start does not
     * count for that round
     */
    /**
     * R3 (participant-linking.md §0a): a late link that is proof - a claim link, an approved claim, the organiser's link
     * - may propose ("connect even during it"); only a claim from a name (or an old link without a method) is refused
     */
    public function testALateLinkThatIsProofMayPropose(): void
    {
        foreach (['claim_link', 'claim_approved', 'organiser', 'import', 'online_link', 'self_join'] as $method) {
            self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, TestClock::now()->modify('-5 minutes'));
            self::db()->executeStatement('UPDATE competition_participant SET connected_via = :via WHERE id = :id', ['via' => $method, 'id' => OfficialResultsFixture::PARTICIPANT_EVA]);

            $answer = self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS);

            self::assertNotSame('linked_after_start', $answer->reason, $method);
            self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status, $method);
            self::db()->executeStatement('DELETE FROM competition_time_proposal');
        }

        // A claim from a name stays refused
        self::db()->executeStatement("UPDATE competition_participant SET connected_via = 'claim' WHERE id = :id", ['id' => OfficialResultsFixture::PARTICIPANT_EVA]);
        self::assertSame('linked_after_start', self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS)->reason);
    }

    public function testALinkMadeAfterTheRoundsStartIsRefused(): void
    {
        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, TestClock::now()->modify('-5 minutes'));

        $answer = self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS);

        self::assertSame('linked_after_start', $answer->reason);
        self::assertSame(0, self::proposalCount());

        // The same link made before the start counts
        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, TestClock::now()->modify('-11 minutes'));
        self::assertSame(TimeProposalAnswer::PROPOSED, self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS)->status);
    }

    public function testATeammatesSecondTapWhileOneIsPendingIsRefusedWithThePendingOne(): void
    {
        self::link(OfficialResultsFixture::PARTICIPANT_EVA, PlayerFixture::PLAYER_PRIVATE, TestClock::now()->modify('-1 day'));
        $first = self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR, roundId: OfficialResultsFixture::ROUND_PAIRS);

        $second = self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_PRIVATE, roundId: OfficialResultsFixture::ROUND_PAIRS);

        self::assertSame('already_proposed', $second->reason);
        self::assertSame($first->proposalId, $second->proposalId);
        self::assertSame(1, self::proposalCount());
    }

    public function testAnEntryWithAResultTakesNoProposal(): void
    {
        // Anna is a member of the Puzzle Sharks, who have a result
        self::assertSame('result_recorded', self::propose(self::SHARKS, roundId: OfficialResultsFixture::ROUND_PAIRS)->reason);
    }

    public function testAnEntryOfAnotherRoundIsNotInTheRound(): void
    {
        self::assertSame('entry_not_in_round', self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR)->reason);
        self::assertSame('entry_not_in_round', self::propose('participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA)->reason);
    }

    public function testOnlyWhileTheStopwatchRuns(): void
    {
        // Never started
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, null);
        self::assertSame('not_running', self::propose(self::ANNA_FINAL)->reason);

        // A countdown
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, TestClock::now()->modify('+5 minutes'), 'scheduled');
        self::assertSame('not_running', self::propose(self::ANNA_FINAL)->reason);

        // Paused
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, TestClock::now()->modify('-10 minutes'), 'stopped', TestClock::now()->modify('-1 minute'));
        self::assertSame('not_running', self::propose(self::ANNA_FINAL)->reason);

        // Tapped before the start
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, TestClock::now()->modify('-1 second'));
        self::assertSame('not_running', self::propose(self::ANNA_FINAL, tappedAgoMs: 3000)->reason);

        self::assertSame(0, self::proposalCount());
    }

    public function testATapInTheFutureOrAfterTheTimeLimitIsRefused(): void
    {
        // 2 s ahead of the receipt is a clock's error, 2.1 s is not
        self::assertSame('clock_ahead', self::propose(self::ANNA_FINAL, tappedAgoMs: -2100)->reason);
        self::assertSame(TimeProposalAnswer::PROPOSED, self::propose(self::ANNA_FINAL, tappedAgoMs: -1500)->status);
    }

    public function testATapAfterTheTimeLimitIsTheJudgesUnfinishedResult(): void
    {
        // 90 minutes limit, started 91 minutes ago
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, TestClock::now()->modify('-91 minutes'));

        self::assertSame('times_up', self::propose(self::ANNA_FINAL)->reason);
    }

    public function testAnOfflineTapArrivingLongAfterTheRoundIsTooLate(): void
    {
        // Ended 31 minutes ago (90 + 31), tapped 60 minutes into the round
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, TestClock::now()->modify('-121 minutes'));

        self::assertSame('too_late', self::propose(self::ANNA_FINAL, tappedAgoMs: 61 * 60_000)->reason);

        // Ended 29 minutes ago: still accepted, flagged late
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, TestClock::now()->modify('-119 minutes'));
        $answer = self::propose(self::ANNA_FINAL, tappedAgoMs: 59 * 60_000);
        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        assert($answer->proposalId !== null);
        self::assertSame(60 * 60, self::proposalRow($answer->proposalId)['proposed_seconds']);
    }

    public function testMoreThanFiveSecondsBetweenTapAndReceiptIsFlaggedLate(): void
    {
        $onTime = self::propose(self::ANNA_FINAL, tappedAgoMs: 5000);
        assert($onTime->proposalId !== null);
        self::assertSame(5000, self::proposalRow($onTime->proposalId)['late_by_ms']);
        self::withdraw($onTime->proposalId);

        $late = self::propose(self::ANNA_FINAL, tappedAgoMs: 5100);
        assert($late->proposalId !== null);
        $row = self::proposalRow($late->proposalId);
        self::assertSame(5100, $row['late_by_ms']);
        self::assertSame(594, $row['proposed_seconds']);
        self::assertSame(600, $row['receipt_seconds']);

        $views = self::getContainer()->get(\SpeedPuzzling\Web\Query\GetTimeProposals::class)->byIds(OfficialResultsFixture::ROUND_FINAL, [$onTime->proposalId, $late->proposalId]);
        // Oldest tap first - the late one was tapped earlier
        self::assertSame([$late->proposalId => true, $onTime->proposalId => false], array_column(array_map(static fn ($view): array => ['id' => $view->id, 'late' => $view->late], $views), 'late', 'id'));
    }

    public function testAPhoneTimedAgainstAnotherStopwatchStateIsFlagged(): void
    {
        $current = self::propose(self::ANNA_FINAL, stateHash: self::finalStateHash());
        assert($current->proposalId !== null);
        self::assertFalse(self::proposalRow($current->proposalId)['clock_changed']);
        self::withdraw($current->proposalId);

        $stale = self::propose(self::ANNA_FINAL, stateHash: 'abcdef012345');
        assert($stale->proposalId !== null);
        self::assertTrue(self::proposalRow($stale->proposalId)['clock_changed']);
    }

    public function testACompetitionWithoutProposalsTakesNone(): void
    {
        self::proposalsOn(false);

        self::assertSame('proposals_off', self::propose(self::ANNA_FINAL)->reason);
        self::assertSame(0, self::proposalCount());
    }

    public function testTheDatabaseKeepsOnePendingProposalPerEntry(): void
    {
        $first = self::propose(self::ANNA_FINAL);
        assert($first->proposalId !== null);
        $row = self::proposalRow($first->proposalId);

        $this->expectException(UniqueConstraintViolationException::class);

        $row['id'] = Uuid::uuid7()->toString();
        $boolean = \Doctrine\DBAL\ParameterType::BOOLEAN;
        self::db()->insert('competition_time_proposal', $row, ['clock_changed' => $boolean, 'clock_uncertain' => $boolean, 'queued_offline' => $boolean]);
    }

    public function testAProposalBelongsToExactlyOneEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        new \SpeedPuzzling\Web\Entity\CompetitionTimeProposal(
            Uuid::uuid7(),
            $em->getRepository(\SpeedPuzzling\Web\Entity\CompetitionRound::class)->find(OfficialResultsFixture::ROUND_FINAL) ?? throw new \LogicException(),
            null,
            null,
            $em->getRepository(\SpeedPuzzling\Web\Entity\CompetitionParticipant::class)->find(OfficialResultsFixture::PARTICIPANT_ANNA) ?? throw new \LogicException(),
            null,
            null,
            1,
            1,
            null,
            null,
            null,
            0,
            0,
            0,
            0,
            false,
        );
    }

    public function testALinkedMemberWithdrawsWhilePending(): void
    {
        $proposal = self::propose(self::ANNA_FINAL);
        assert($proposal->proposalId !== null);

        self::assertSame('not_your_entry', self::withdraw($proposal->proposalId, PlayerFixture::PLAYER_REGULAR)->reason);
        self::assertSame('pending', self::proposalRow($proposal->proposalId)['status']);

        $withdrawn = self::withdraw($proposal->proposalId);
        self::assertSame(TimeProposalAnswer::WITHDRAWN, $withdrawn->status);
        self::assertTrue($withdrawn->changed());
        self::assertSame('withdrawn', self::proposalRow($proposal->proposalId)['status']);

        // Again: answered with its state, nothing changes
        self::assertSame(TimeProposalAnswer::KNOWN, self::withdraw($proposal->proposalId)->status);
        self::assertSame('proposal_not_found', self::withdraw(Uuid::uuid7()->toString())->reason);

        // A new tap after the undo is a new proposal
        self::assertSame(TimeProposalAnswer::PROPOSED, self::propose(self::ANNA_FINAL)->status);
    }

    public function testAStopwatchResetSupersedesThePendingProposals(): void
    {
        $proposal = self::propose(self::ANNA_FINAL);
        assert($proposal->proposalId !== null);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $superseded = self::bus()->dispatch(new ResetRoundStopwatch(OfficialResultsFixture::ROUND_FINAL, OfficialResultsFixture::COMPETITION_RESULTS_CUP))
            ->last(HandledStamp::class)?->getResult();

        self::assertInstanceOf(TimeProposalsSuperseded::class, $superseded);
        self::assertSame([$proposal->proposalId], $superseded->proposalIds);
        $row = self::proposalRow($proposal->proposalId);
        self::assertSame('superseded', $row['status']);
        self::assertSame('stopwatch_reset', $row['decision_reason']);
        self::assertNull($row['decided_by_player_id']);
    }

    public function testDeletingTheCompetitionTakesItsProposals(): void
    {
        self::propose(self::ANNA_FINAL);
        self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR, roundId: OfficialResultsFixture::ROUND_PAIRS);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::bus()->dispatch(new DeleteCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP));

        self::assertSame(0, self::proposalCount());
    }

    private static function finalStateHash(): string
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $round = self::getContainer()->get(\SpeedPuzzling\Web\Repository\CompetitionRoundRepository::class)->get(OfficialResultsFixture::ROUND_FINAL);

        return RoundClock::fromRound($round, TestClock::now())->stateHash();
    }
}
