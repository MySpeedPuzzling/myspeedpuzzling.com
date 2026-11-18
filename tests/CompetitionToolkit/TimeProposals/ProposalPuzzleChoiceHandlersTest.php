<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\ChangeTimeProposalPuzzles;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Query\GetTimeProposals;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Results\TimeProposalView;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The puzzle choice of a time proposal (competition toolkit v2 C2, design-v2 competitors-splits.md §2.2): stored with
 * a new proposal, a bad choice dropped and said while the time is stored all the same, a resend never changing it,
 * and ChangeTimeProposalPuzzles - only the proposer, only while pending.
 */
final class ProposalPuzzleChoiceHandlersTest extends KernelTestCase
{
    use TimeProposalScenario;
    use PickFinalScenario;

    private string $blueBox;

    protected function setUp(): void
    {
        self::bootKernel();
        self::runningRounds();
        $this->blueBox = self::pickFinal(self::db());
    }

    public function testTheChoiceIsStoredWithTheProposal(): void
    {
        $proposalId = self::newProposalId();
        $answer = self::propose(self::ANNA_FINAL, proposalId: $proposalId, puzzles: [['position' => 2, 'label' => 'Blue box'], ['position' => 1, 'label' => 'A']]);

        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        self::assertNull($answer->puzzlesRefused);
        self::assertSame([$this->blueBox, self::PICK_SECRET], self::storedChoice($proposalId));
        self::assertSame(598, self::proposalRow($proposalId)['proposed_seconds']);
    }

    /**
     * @return iterable<string, array{non-empty-list<array{position: int, label: string}>, string}>
     */
    public static function badChoices(): iterable
    {
        yield 'relabelled since' => [[['position' => 2, 'label' => 'B']], 'puzzles_changed'];
        yield 'a place the round does not have' => [[['position' => 4, 'label' => 'D']], 'unknown_round_puzzle'];
        yield 'twice' => [[['position' => 3, 'label' => 'C'], ['position' => 3, 'label' => 'C']], 'puzzle_listed_twice'];
        yield 'more than K' => [[['position' => 1, 'label' => 'A'], ['position' => 2, 'label' => 'Blue box'], ['position' => 3, 'label' => 'C']], 'too_many_puzzles'];
    }

    /**
     * @param non-empty-list<array{position: int, label: string}> $puzzles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badChoices')]
    public function testABadChoiceIsDroppedTheTimeIsStored(array $puzzles, string $reason): void
    {
        $proposalId = self::newProposalId();
        $answer = self::propose(self::ANNA_FINAL, proposalId: $proposalId, puzzles: $puzzles);

        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        self::assertNull($answer->reason);
        self::assertSame($reason, $answer->puzzlesRefused);
        self::assertTrue($answer->changed());
        self::assertSame('pending', self::proposalRow($proposalId)['status']);
        self::assertNull(self::proposalRow($proposalId)['puzzle_choice']);
    }

    public function testARoundWithoutAChoiceDropsItTheTimeIsStored(): void
    {
        $answer = self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR, roundId: OfficialResultsFixture::ROUND_PAIRS, puzzles: [['position' => 1, 'label' => 'A']]);

        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        self::assertSame('puzzles_not_used', $answer->puzzlesRefused);
        assert($answer->proposalId !== null);
        self::assertNull(self::proposalRow($answer->proposalId)['puzzle_choice']);
    }

    public function testAResendNeverChangesTheStoredChoice(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId, puzzles: [['position' => 3, 'label' => 'C']]);

        $again = self::propose(self::ANNA_FINAL, proposalId: $proposalId, puzzles: [['position' => 1, 'label' => 'A']]);

        self::assertSame(TimeProposalAnswer::KNOWN, $again->status);
        self::assertNull($again->puzzlesRefused);
        self::assertSame([self::PICK_PUBLIC], self::storedChoice($proposalId));
    }

    public function testAProposalWithoutAChoiceReadsNothingOfTheRoundsPuzzles(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        /** @var DebugDataHolder $debugData */
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $answer = self::propose(self::ANNA_FINAL);

        self::assertSame(TimeProposalAnswer::PROPOSED, $answer->status);
        self::assertSame([], array_filter(self::statements($debugData), static fn (string $sql): bool => str_contains($sql, 'competition_round_puzzle')));
    }

    public function testTheProposerChangesAndClearsTheChoiceWhilePending(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId, puzzles: [['position' => 3, 'label' => 'C']]);

        $changed = self::choose($proposalId, [['position' => 1, 'label' => 'A'], ['position' => 2, 'label' => 'Blue box']]);
        self::assertSame(TimeProposalAnswer::CHOSEN, $changed->status);
        self::assertTrue($changed->changed());
        self::assertSame(OfficialResultsFixture::ROUND_FINAL, $changed->roundId);
        self::assertSame([self::PICK_SECRET, $this->blueBox], self::storedChoice($proposalId));
        // The entry is not touched - only the judge's Confirm records puzzles
        self::assertNull(self::db()->fetchOne('SELECT result_puzzles FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_FINAL_ANNA]));

        $refused = self::choose($proposalId, [['position' => 2, 'label' => 'B']]);
        self::assertSame('puzzles_changed', $refused->reason);
        self::assertSame([self::PICK_SECRET, $this->blueBox], self::storedChoice($proposalId));

        self::assertSame(TimeProposalAnswer::CHOSEN, self::choose($proposalId, null)->status);
        self::assertNull(self::proposalRow($proposalId)['puzzle_choice']);
    }

    public function testOnlyTheProposerChooses(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId);

        $answer = self::choose($proposalId, [['position' => 3, 'label' => 'C']], PlayerFixture::PLAYER_REGULAR);

        self::assertSame(TimeProposalAnswer::REFUSED, $answer->status);
        self::assertSame('not_your_entry', $answer->reason);
        self::assertNull($answer->proposalId);
        self::assertNull(self::proposalRow($proposalId)['puzzle_choice']);
    }

    public function testAProfileLinkedAfterTheStartDoesNotChoose(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId);
        // A claim from a name, a minute ago - after the round's start (a late link that is no proof)
        self::link(OfficialResultsFixture::PARTICIPANT_ANNA, PlayerFixture::PLAYER_ADMIN, TestClock::now()->modify('-1 minute'));
        self::db()->executeStatement("UPDATE competition_participant SET connected_via = 'claim' WHERE id = :id", ['id' => OfficialResultsFixture::PARTICIPANT_ANNA]);

        self::assertSame('linked_after_start', self::choose($proposalId, [['position' => 3, 'label' => 'C']])->reason);
        self::assertNull(self::proposalRow($proposalId)['puzzle_choice']);
    }

    public function testADecidedProposalKeepsItsChoice(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId, puzzles: [['position' => 3, 'label' => 'C']]);
        self::withdraw($proposalId);

        $answer = self::choose($proposalId, [['position' => 1, 'label' => 'A']]);

        self::assertSame(TimeProposalAnswer::KNOWN, $answer->status);
        self::assertFalse($answer->changed());
        self::assertSame([self::PICK_PUBLIC], self::storedChoice($proposalId));
    }

    public function testALateReplayNeverOverwritesANewerChoice(): void
    {
        $proposalId = self::newProposalId();
        // Tapped 2 s ago with C - the choice's moment is the tap's
        self::propose(self::ANNA_FINAL, proposalId: $proposalId, puzzles: [['position' => 3, 'label' => 'C']]);
        self::assertEquals(self::nowMs() - 2000, self::proposalRow($proposalId)['puzzle_choice_at_ms']);

        // Changed on the phone 1 s ago to A, then an older request (made before the tap) arrives late
        self::assertSame(TimeProposalAnswer::CHOSEN, self::choose($proposalId, [['position' => 1, 'label' => 'A']], chosenAtMs: self::nowMs() - 1000)->status);
        $replay = self::choose($proposalId, [['position' => 2, 'label' => 'Blue box']], chosenAtMs: self::nowMs() - 1500);

        self::assertSame(TimeProposalAnswer::KNOWN, $replay->status);
        self::assertFalse($replay->changed());
        self::assertSame([self::PICK_SECRET], self::storedChoice($proposalId));

        // An old client that says no moment still applies, and keeps the stamp
        self::assertSame(TimeProposalAnswer::CHOSEN, self::choose($proposalId, null)->status);
        self::assertEquals(self::nowMs() - 1000, self::proposalRow($proposalId)['puzzle_choice_at_ms']);
    }

    public function testNoChoiceOnceTheEventTakesNoProposals(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId);
        self::proposalsOn(false);

        self::assertSame('proposals_off', self::choose($proposalId, [['position' => 3, 'label' => 'C']])->reason);
        self::assertNull(self::proposalRow($proposalId)['puzzle_choice']);
    }

    public function testNoChoiceLongAfterTheRoundsEnd(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId);
        // Stopped 31 minutes ago - past the 30 minutes a proposal is still taken after the end
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, TestClock::now()->modify('-50 minutes'), 'stopped', TestClock::now()->modify('-31 minutes'));

        self::assertSame('too_late', self::choose($proposalId, [['position' => 3, 'label' => 'C']])->reason);
        self::assertNull(self::proposalRow($proposalId)['puzzle_choice']);

        // Within the grace it is still taken
        self::stopwatch(OfficialResultsFixture::ROUND_FINAL, TestClock::now()->modify('-50 minutes'), 'stopped', TestClock::now()->modify('-29 minutes'));
        self::assertSame(TimeProposalAnswer::CHOSEN, self::choose($proposalId, [['position' => 3, 'label' => 'C']])->status);
    }

    public function testAProposalOfAnotherEventIsNotFound(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId);

        $answer = self::choose($proposalId, [['position' => 3, 'label' => 'C']], competitionId: '018d0004-0000-0000-0000-000000000002');

        self::assertSame('proposal_not_found', $answer->reason);
        self::assertSame('proposal_not_found', self::choose(self::newProposalId(), null)->reason);
    }

    public function testTheJudgesReadTheIdsTheCompetitorThePlaces(): void
    {
        $proposalId = self::newProposalId();
        self::propose(self::ANNA_FINAL, proposalId: $proposalId, puzzles: [['position' => 2, 'label' => 'Blue box'], ['position' => 1, 'label' => 'A']]);

        $view = self::viewOf(OfficialResultsFixture::ROUND_FINAL, $proposalId);
        $judges = $view->jsonSerialize();
        $competitor = $view->forCompetitor()->jsonSerialize();

        self::assertSame([$this->blueBox, self::PICK_SECRET], $judges['puzzles']);
        self::assertSame([['position' => 2, 'label' => 'Blue box'], ['position' => 1, 'label' => 'A']], $competitor['puzzles']);
        $competitorJson = json_encode($competitor, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::PICK_SECRET, $competitorJson);
        self::assertStringNotContainsString(self::PICK_SECRET_NAME, $competitorJson);

        // A puzzle removed from the round since drops out
        self::db()->executeStatement('DELETE FROM competition_round_puzzle WHERE id = :id', ['id' => self::PICK_SECRET]);
        $after = self::viewOf(OfficialResultsFixture::ROUND_FINAL, $proposalId, viaRound: true);
        self::assertSame([$this->blueBox], $after->jsonSerialize()['puzzles']);
        // Its place moved: "Blue box" is the first puzzle now
        self::assertSame([['position' => 1, 'label' => 'Blue box']], $after->forCompetitor()->jsonSerialize()['puzzles']);

        // Without a choice the JSON has no `puzzles` key (untouched proposals keep their JSON)
        $plain = self::newProposalId();
        self::propose(self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR, proposalId: $plain, roundId: OfficialResultsFixture::ROUND_PAIRS);
        $plainView = self::viewOf(OfficialResultsFixture::ROUND_PAIRS, $plain);
        self::assertArrayNotHasKey('puzzles', $plainView->jsonSerialize());
        self::assertArrayNotHasKey('puzzles', $plainView->forCompetitor()->jsonSerialize());
    }

    public function testACompetitionWithAChoosingProposalIsDeleted(): void
    {
        self::propose(self::ANNA_FINAL, puzzles: [['position' => 3, 'label' => 'C']]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::bus()->dispatch(new DeleteCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP));

        self::assertSame(0, self::proposalCount());
    }

    /**
     * @param null|non-empty-list<array{position: int, label: string}> $puzzles
     */
    private static function choose(string $proposalId, null|array $puzzles, string $playerId = PlayerFixture::PLAYER_ADMIN, string $competitionId = OfficialResultsFixture::COMPETITION_RESULTS_CUP, null|int $chosenAtMs = null): TimeProposalAnswer
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $answer = self::bus()->dispatch(new ChangeTimeProposalPuzzles($competitionId, $proposalId, $playerId, $puzzles, $chosenAtMs))
            ->last(HandledStamp::class)?->getResult();
        assert($answer instanceof TimeProposalAnswer);

        return $answer;
    }

    private static function viewOf(string $roundId, string $proposalId, bool $viaRound = false): TimeProposalView
    {
        $query = self::getContainer()->get(GetTimeProposals::class);
        $views = $viaRound ? $query->forRound($roundId, TestClock::now()) : $query->byIds($roundId, [$proposalId]);
        $view = $views[0] ?? null;
        self::assertInstanceOf(TimeProposalView::class, $view);

        return $view;
    }

    /**
     * @return null|list<string>
     */
    private static function storedChoice(string $proposalId): null|array
    {
        $stored = self::proposalRow($proposalId)['puzzle_choice'];

        if ($stored === null) {
            return null;
        }

        assert(is_string($stored));
        $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        /** @var list<string> $decoded */
        return $decoded;
    }

    /**
     * @return list<string>
     */
    private static function statements(DebugDataHolder $debugData): array
    {
        $statements = [];

        foreach ($debugData->getData() as $queries) {
            if (!is_array($queries)) {
                continue;
            }

            foreach ($queries as $query) {
                if (is_array($query) && is_string($query['sql'] ?? null)) {
                    $statements[] = $query['sql'];
                }
            }
        }

        return $statements;
    }
}
