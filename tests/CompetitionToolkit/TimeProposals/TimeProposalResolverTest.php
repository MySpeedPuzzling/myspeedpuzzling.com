<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Confirming and rejecting time proposals through the official results write path (competition toolkit P1,
 * TimeProposalResolver inside RecordRoundResultsHandler - B-qr-competitor-page.md §5.5, R-review-and-plan.md §1.2).
 * Anna proposed 598 s in the Final (tapped 2 s before now, 10 minutes after the start).
 */
final class TimeProposalResolverTest extends KernelTestCase
{
    use TimeProposalScenario;

    private string $proposalId;

    protected function setUp(): void
    {
        self::bootKernel();
        self::runningRounds();
        $answer = self::propose(self::ANNA_FINAL);
        assert($answer->proposalId !== null);
        $this->proposalId = $answer->proposalId;
    }

    public function testAResultChangeWithTheProposalConfirmsIt(): void
    {
        $recorded = self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598], $this->proposalId)]);

        self::assertSame('applied', $recorded->outcomes[0]->status->value);
        self::assertSame(['id' => $this->proposalId, 'status' => 'confirmed'], $recorded->outcomes[0]->jsonSerialize()['proposal']);
        self::assertSame([$this->proposalId], $recorded->changedProposalIds);

        $row = self::proposalRow($this->proposalId);
        self::assertSame('confirmed', $row['status']);
        self::assertSame(598, $row['decided_seconds']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $row['decided_by_player_id']);
        self::assertSame(598, self::db()->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_FINAL_ANNA]));
    }

    public function testTheJudgesEditedTimeIsTheConfirmedOne(): void
    {
        self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 601], $this->proposalId)]);

        $row = self::proposalRow($this->proposalId);
        self::assertSame('confirmed', $row['status']);
        self::assertSame(601, $row['decided_seconds']);
        self::assertSame(598, $row['proposed_seconds']);
    }

    public function testConfirmedWithSomethingOtherThanAFinishedTimeIsRejectedAsNotFinished(): void
    {
        $recorded = self::record([self::resultChange(self::ANNA_FINAL, null, ['piecesPlaced' => 812], $this->proposalId)]);

        self::assertSame('rejected', $recorded->outcomes[0]->proposal['status'] ?? null);
        $row = self::proposalRow($this->proposalId);
        self::assertSame('rejected', $row['status']);
        self::assertSame('not_finished', $row['decision_reason']);
    }

    public function testAProposalWithdrawnMeanwhileLeavesTheJudgesResultStanding(): void
    {
        self::withdraw($this->proposalId);

        $recorded = self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598], $this->proposalId)]);

        self::assertSame('applied', $recorded->outcomes[0]->status->value);
        self::assertSame(['id' => $this->proposalId, 'status' => 'not_pending'], $recorded->outcomes[0]->proposal);
        self::assertSame([], $recorded->changedProposalIds);
        self::assertSame('withdrawn', self::proposalRow($this->proposalId)['status']);
    }

    public function testAnOfflineReplayOfTheConfirmationTouchesNothing(): void
    {
        $change = self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598], $this->proposalId);
        self::record([$change]);
        $decidedAt = self::proposalRow($this->proposalId)['decided_at'];

        $replayed = self::record([$change]);

        self::assertSame('unchanged', $replayed->outcomes[0]->status->value);
        self::assertSame(['id' => $this->proposalId, 'status' => 'confirmed'], $replayed->outcomes[0]->proposal);
        self::assertSame([], $replayed->changedProposalIds);
        self::assertSame($decidedAt, self::proposalRow($this->proposalId)['decided_at']);
    }

    public function testAProposalOfAnotherEntryIsUnknownAndTheResultStands(): void
    {
        $pair = self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR, roundId: OfficialResultsFixture::ROUND_PAIRS);
        assert($pair->proposalId !== null);

        $recorded = self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598], $pair->proposalId)]);

        self::assertSame('applied', $recorded->outcomes[0]->status->value);
        self::assertSame(['id' => $pair->proposalId, 'status' => 'unknown'], $recorded->outcomes[0]->proposal);
        self::assertSame('pending', self::proposalRow($pair->proposalId)['status']);
        // Anna's own proposal: the result was recorded without it - same seconds = confirmed
        self::assertSame('pending', self::proposalRow($this->proposalId)['status'], 'a change naming a proposal resolves only that one');
    }

    public function testAResultWithoutTheProposalConfirmsItWhenTheSecondsAreTheSame(): void
    {
        $recorded = self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598])]);

        self::assertSame(['id' => $this->proposalId, 'status' => 'confirmed'], $recorded->outcomes[0]->proposal);
        self::assertSame('confirmed', self::proposalRow($this->proposalId)['status']);
    }

    public function testAnotherResultRecordedWithoutTheProposalSupersedesIt(): void
    {
        $recorded = self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 700])]);

        self::assertSame(['id' => $this->proposalId, 'status' => 'superseded'], $recorded->outcomes[0]->proposal);
        $row = self::proposalRow($this->proposalId);
        self::assertSame('superseded', $row['status']);
        self::assertSame('result_recorded', $row['decision_reason']);
    }

    /**
     * Toolkit H3: the judge's Undo of a confirmation clears the result - the proposal loses its confirmation in the same
     * transaction, and the competitor may propose again while the round runs
     */
    public function testUndoingAConfirmationSupersedesTheProposalAndAllowsANewOne(): void
    {
        self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 601], $this->proposalId)]);

        $undo = self::record([self::resultChange(self::ANNA_FINAL, ['seconds' => 601], null)]);

        self::assertSame('applied', $undo->outcomes[0]->status->value);
        self::assertSame(['id' => $this->proposalId, 'status' => 'superseded'], $undo->outcomes[0]->proposal);
        self::assertSame([$this->proposalId], $undo->changedProposalIds);
        $row = self::proposalRow($this->proposalId);
        self::assertSame('superseded', $row['status']);
        self::assertSame('result_cleared', $row['decision_reason']);
        self::assertSame(601, $row['decided_seconds'], 'what it was confirmed as stays');

        $again = self::propose(self::ANNA_FINAL, tappedAgoMs: 1000);
        self::assertSame('proposed', $again->status, (string) $again->reason);
    }

    /**
     * Decided: a confirmation whose result a judge then changes to another value is superseded too (`result_changed`) -
     * "Confirmed as X" would no longer be the official result; the competitor page shows the result is recorded
     */
    public function testChangingAConfirmedResultToAnotherValueSupersedesTheProposal(): void
    {
        self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598], $this->proposalId)]);

        $changed = self::record([self::resultChange(self::ANNA_FINAL, ['seconds' => 598], ['piecesPlaced' => 900])]);

        self::assertSame(['id' => $this->proposalId, 'status' => 'superseded'], $changed->outcomes[0]->proposal);
        $row = self::proposalRow($this->proposalId);
        self::assertSame('superseded', $row['status']);
        self::assertSame('result_changed', $row['decision_reason']);
    }

    public function testTheSameResultSentAgainKeepsTheConfirmation(): void
    {
        self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598], $this->proposalId)]);

        $same = self::record([self::resultChange(self::ANNA_FINAL, ['seconds' => 598], ['seconds' => 598])]);

        self::assertSame('unchanged', $same->outcomes[0]->status->value);
        self::assertSame([], $same->changedProposalIds);
        self::assertSame('confirmed', self::proposalRow($this->proposalId)['status']);
    }

    public function testAJudgeRejectsAProposalThroughTheChangeSet(): void
    {
        $recorded = self::record([self::rejectChange(self::ANNA_FINAL, $this->proposalId, 'wrong_entry')], resultsOnly: true, actingPlayerId: PlayerFixture::PLAYER_REGULAR);

        self::assertSame('applied', $recorded->outcomes[0]->status->value);
        self::assertSame(['id' => $this->proposalId, 'status' => 'rejected'], $recorded->outcomes[0]->proposal);
        self::assertSame([], $recorded->changedEntryRefs);
        self::assertSame([$this->proposalId], $recorded->changedProposalIds);
        $row = self::proposalRow($this->proposalId);
        self::assertSame('rejected', $row['status']);
        self::assertSame('wrong_entry', $row['decision_reason']);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['decided_by_player_id']);
        self::assertNull(self::db()->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_FINAL_ANNA]));

        // Rejected again by another judge - no longer pending
        $again = self::record([self::rejectChange(self::ANNA_FINAL, $this->proposalId, 'other')]);
        self::assertSame('unchanged', $again->outcomes[0]->status->value);
        self::assertSame(['id' => $this->proposalId, 'status' => 'not_pending'], $again->outcomes[0]->proposal);
        self::assertSame('wrong_entry', self::proposalRow($this->proposalId)['decision_reason']);
    }

    public function testARejectionOfAProposalNotOfThatEntryIsRefused(): void
    {
        $recorded = self::record([self::rejectChange(self::ANNA_FINAL, Uuid::uuid7()->toString())]);

        self::assertSame('rejected', $recorded->outcomes[0]->status->value);
        self::assertSame('proposal_not_found', $recorded->outcomes[0]->reason);
        self::assertSame('pending', self::proposalRow($this->proposalId)['status']);
    }

    public function testAnUnreadableRejectionIsAnInvalidChange(): void
    {
        $change = self::rejectChange(self::ANNA_FINAL, $this->proposalId);
        $change['to'] = ['status' => 'rejected', 'reason' => 'result_recorded'];

        self::assertSame('invalid_change', self::record([$change])->outcomes[0]->reason);

        // A proposal id on anything but a result
        $tableChange = ['clientChangeId' => Uuid::uuid7()->toString(), 'entry' => self::ANNA_FINAL, 'field' => 'table_number', 'from' => null, 'to' => 3, 'proposalId' => $this->proposalId];
        self::assertSame('invalid_change', self::record([$tableChange])->outcomes[0]->reason);
    }

    public function testWithProposalsOffNothingNewHappens(): void
    {
        self::proposalsOn(false);

        $rejection = self::record([self::rejectChange(self::ANNA_FINAL, $this->proposalId)]);
        self::assertSame('rejected', $rejection->outcomes[0]->status->value);
        self::assertSame('proposals_off', $rejection->outcomes[0]->reason);

        $confirmation = self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 598], $this->proposalId)]);
        self::assertSame('applied', $confirmation->outcomes[0]->status->value);
        self::assertSame(['id' => $this->proposalId, 'status' => 'unknown'], $confirmation->outcomes[0]->proposal);
        self::assertSame('pending', self::proposalRow($this->proposalId)['status']);
    }

    /**
     * R §1.2 / B §8: the resolver is skipped entirely while proposals are off - a plain result set reads nothing of
     * them, and its outcomes keep their shape
     */
    public function testWithProposalsOffTheWritePathReadsNoProposal(): void
    {
        self::proposalsOn(false);

        $statements = self::statementsOf(fn (): RecordedRoundResults => self::record([self::resultChange(self::ANNA_FINAL, null, ['seconds' => 650])]));

        self::assertSame([], array_values(array_filter($statements['sql'], static fn (string $sql): bool => str_contains($sql, 'competition_time_proposal'))));
        self::assertArrayNotHasKey('proposal', $statements['recorded']->outcomes[0]->jsonSerialize());
    }

    public function testWithProposalsOnASetWithoutResultsReadsNoProposalEither(): void
    {
        $statements = self::statementsOf(fn (): RecordedRoundResults => self::record([
            ['clientChangeId' => Uuid::uuid7()->toString(), 'entry' => self::ANNA_FINAL, 'field' => 'table_number', 'from' => null, 'to' => 3],
        ]));

        self::assertSame([], array_values(array_filter($statements['sql'], static fn (string $sql): bool => str_contains($sql, 'competition_time_proposal'))));
    }

    /**
     * In every locale of the site, each in its own catalogue - a key missing there would show in English (competition
     * toolkit G3).
     */
    public function testTheNewReasonsAreTranslated(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $keys = [
            ...array_map(static fn (string $reason): string => 'official_results.reason.' . $reason, ['proposals_off', 'proposal_not_found']),
            ...array_map(static fn (string $reason): string => 'time_proposal.reason.' . $reason, ['sign_in_required', 'invalid_proposal', 'rate_limited', 'round_not_found', 'proposals_off', 'invalid_proposal_id', 'entry_not_in_round', 'not_your_entry', 'linked_after_start', 'clock_ahead', 'not_running', 'times_up', 'too_late', 'result_recorded', 'already_proposed', 'proposal_not_found']),
        ];
        $missing = [];

        foreach (['cs', 'de', 'en', 'es', 'fr', 'ja'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);

            foreach ($keys as $key) {
                if (!$catalogue->defines($key)) {
                    $missing[] = $locale . ': ' . $key;
                }
            }
        }

        self::assertSame([], $missing, 'Keys missing in a locale\'s own catalogue (the page would fall back to English)');
    }

    /**
     * @param \Closure(): RecordedRoundResults $record
     * @return array{sql: list<string>, recorded: RecordedRoundResults}
     */
    private static function statementsOf(\Closure $record): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        /** @var DebugDataHolder $debugData */
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $recorded = $record();

        $sql = [];
        foreach ($debugData->getData() as $queries) {
            foreach (is_array($queries) ? $queries : [] as $query) {
                if (is_array($query) && is_string($query['sql'] ?? null)) {
                    $sql[] = $query['sql'];
                }
            }
        }

        return ['sql' => $sql, 'recorded' => $recorded];
    }
}
