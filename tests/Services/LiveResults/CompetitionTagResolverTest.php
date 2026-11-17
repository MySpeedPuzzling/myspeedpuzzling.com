<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\LiveResults;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Results\CompetitionTagTarget;
use SpeedPuzzling\Web\Services\CompetitionTagResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The resolver matrix (toolkit B-qr-competitor-page.md §9): every tag kind → which entry in which round now. The
 * fixture's rounds were ten days ago - none runs, none started lately, none is next: the current one of a set is its
 * last by start (Group A 9:00, Group B 11:00, Pairs 13:00, Final 15:00, Pairs Final 17:00).
 */
final class CompetitionTagResolverTest extends KernelTestCase
{
    private const string UNKNOWN = '018d0020-0000-0000-0000-00000000ffff';

    private CompetitionTagResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = self::getContainer()->get(CompetitionTagResolver::class);
    }

    public function testAPersonIsTheirCurrentRoundsEntry(): void
    {
        // Anna: Group A, Pairs (Puzzle Sharks), Final - the last of hers
        self::assertTarget(true, F::ROUND_FINAL, 'participant_round:' . F::ENTRY_FINAL_ANNA, $this->resolve(CompetitionTag::participant(F::PARTICIPANT_ANNA)));
        // Ivan: Group B only
        self::assertTarget(true, F::ROUND_GROUP_B, 'participant_round:' . F::ENTRY_B_IVAN, $this->resolve(CompetitionTag::participant(F::PARTICIPANT_IVAN)));

        // While the Pairs run, Anna's entry is her pair
        $this->startStopwatch(F::ROUND_PAIRS, '-20 minutes');
        self::assertTarget(true, F::ROUND_PAIRS, 'team:' . F::TEAM_SHARKS, $this->resolve(CompetitionTag::participant(F::PARTICIPANT_ANNA)));
    }

    public function testAPersonOfNoRoundIsKnownWithoutAnEntry(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, F::COMPETITION_RESULTS_CUP);
        self::assertNotNull($competition);
        $participant = new CompetitionParticipant(Uuid::uuid7(), 'Nora Noround', 'cz', $competition);
        $entityManager->persist($participant);
        $entityManager->flush();
        $participantId = $participant->id->toString();

        self::assertTarget(true, F::ROUND_PAIRS_FINAL, null, $this->resolve(CompetitionTag::participant($participantId)));
    }

    public function testAnUnknownRemovedOrForeignPersonIsUnknownInTheEventsCurrentRound(): void
    {
        self::assertTarget(false, F::ROUND_PAIRS_FINAL, null, $this->resolve(CompetitionTag::participant(self::UNKNOWN)));
        self::assertTarget(false, F::ROUND_PAIRS_FINAL, null, $this->resolve(CompetitionTag::participant(CompetitionParticipantFixture::PARTICIPANT_CONNECTED)));

        $this->connection()->executeStatement('UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => F::PARTICIPANT_IVAN]);
        self::assertTarget(false, F::ROUND_PAIRS_FINAL, null, $this->resolve(CompetitionTag::participant(F::PARTICIPANT_IVAN)));
    }

    public function testATableIsWhoeverSitsThereInTheCurrentRoundThatUsesIt(): void
    {
        // Table 1 is used by Group A, Group B and the Pairs - the last of them
        self::assertTarget(true, F::ROUND_PAIRS, 'team:' . F::TEAM_SHARKS, $this->resolve(CompetitionTag::table(1)));
        // Table 5 only by Group A
        self::assertTarget(true, F::ROUND_GROUP_A, 'participant_round:' . F::ENTRY_A_EVA, $this->resolve(CompetitionTag::table(5)));
        // A table nobody sits at: the event's current round, nobody there
        self::assertTarget(false, F::ROUND_PAIRS_FINAL, null, $this->resolve(CompetitionTag::table(99)));
    }

    public function testATableFollowsTheRunningRoundAcrossParallelHalls(): void
    {
        // Two halls: Group A started half an hour ago, Group B ten minutes ago - both run
        $this->startStopwatch(F::ROUND_GROUP_A, '-30 minutes');
        $this->startStopwatch(F::ROUND_GROUP_B, '-10 minutes');

        // Table 1 is in both: the latest started
        self::assertTarget(true, F::ROUND_GROUP_B, 'participant_round:' . F::ENTRY_B_GINA, $this->resolve(CompetitionTag::table(1)));
        // Table 4 is Group A's only: its hall, although Group B started later
        self::assertTarget(true, F::ROUND_GROUP_A, 'participant_round:' . F::ENTRY_A_DAN, $this->resolve(CompetitionTag::table(4)));
    }

    public function testATableOfAPersonRemovedFromTheEventIsEmpty(): void
    {
        $this->connection()->executeStatement('UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => F::PARTICIPANT_EVA]);

        self::assertTarget(false, F::ROUND_PAIRS_FINAL, null, $this->resolve(CompetitionTag::table(5)));
    }

    public function testARoundEntryIsStaticItsOwnRoundWhateverRuns(): void
    {
        $this->startStopwatch(F::ROUND_GROUP_B, '-10 minutes');

        self::assertTarget(true, F::ROUND_GROUP_A, 'participant_round:' . F::ENTRY_A_BEN, $this->resolve(CompetitionTag::entry(F::ENTRY_A_BEN)));
        self::assertTarget(true, F::ROUND_PAIRS, 'team:' . F::TEAM_CORNERS, $this->resolve(CompetitionTag::entry(F::TEAM_CORNERS)));
        self::assertTarget(true, F::ROUND_PAIRS, 'team:' . F::TEAM_CORNERS, $this->resolve(CompetitionTag::entry(strtoupper(F::TEAM_CORNERS))));

        // A pair member's own row stands for the pair
        $memberRow = $this->connection()->fetchOne('SELECT id FROM competition_participant_round WHERE team_id = :team AND participant_id = :participant', ['team' => F::TEAM_CORNERS, 'participant' => F::PARTICIPANT_CARA]);
        self::assertIsString($memberRow);
        self::assertTarget(true, F::ROUND_PAIRS, 'team:' . F::TEAM_CORNERS, $this->resolve(CompetitionTag::entry($memberRow)));
    }

    public function testARoundEntryMovedToAnotherRoundFollowsIt(): void
    {
        // CompetitionParticipantRound::changeRound() keeps the id
        $this->connection()->executeStatement('UPDATE competition_participant_round SET round_id = :round WHERE id = :id', ['round' => F::ROUND_GROUP_B, 'id' => F::ENTRY_A_FILIP]);

        self::assertTarget(true, F::ROUND_GROUP_B, 'participant_round:' . F::ENTRY_A_FILIP, $this->resolve(CompetitionTag::entry(F::ENTRY_A_FILIP)));
    }

    public function testAnUnknownOrForeignRoundEntryIsUnknown(): void
    {
        self::assertTarget(false, F::ROUND_PAIRS_FINAL, null, $this->resolve(CompetitionTag::entry(self::UNKNOWN)));
        // An entry of the event, scanned as another event's tag
        $target = $this->resolver->resolve(CompetitionFixture::COMPETITION_UNAPPROVED, CompetitionTag::entry(F::ENTRY_A_BEN));
        self::assertFalse($target->known);
        self::assertNull($target->entry);
    }

    public function testALineupIsThePairWithExactlyTheseMembersInItsCurrentRound(): void
    {
        $sharks = CompetitionLineupId::of(F::COMPETITION_RESULTS_CUP, [F::PARTICIPANT_BEN, F::PARTICIPANT_ANNA]);
        self::assertTarget(true, F::ROUND_PAIRS, 'team:' . F::TEAM_SHARKS, $this->resolve(CompetitionTag::lineup($sharks)));

        // The same two play the Pairs Final - a new team row, the same line-up; it runs now
        $finalTeam = $this->addTeam(F::ROUND_PAIRS_FINAL, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]);
        $this->startStopwatch(F::ROUND_PAIRS_FINAL, '-5 minutes');
        self::assertTarget(true, F::ROUND_PAIRS_FINAL, 'team:' . $finalTeam, $this->resolve(CompetitionTag::lineup($sharks)));
    }

    public function testATeamWhoseMembersChangedBetweenRoundsIsAnotherLineup(): void
    {
        // Pairs: Anna + Ben (Puzzle Sharks). Pairs Final: Anna + Cara - running now
        $annaAndCara = $this->addTeam(F::ROUND_PAIRS_FINAL, [F::PARTICIPANT_ANNA, F::PARTICIPANT_CARA]);
        $this->startStopwatch(F::ROUND_PAIRS_FINAL, '-5 minutes');

        $sharks = CompetitionLineupId::of(F::COMPETITION_RESULTS_CUP, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]);
        $newLineup = CompetitionLineupId::of(F::COMPETITION_RESULTS_CUP, [F::PARTICIPANT_ANNA, F::PARTICIPANT_CARA]);

        // The Sharks' tag stays on their round, never the running one they do not play together in
        self::assertTarget(true, F::ROUND_PAIRS, 'team:' . F::TEAM_SHARKS, $this->resolve(CompetitionTag::lineup($sharks)));
        self::assertTarget(true, F::ROUND_PAIRS_FINAL, 'team:' . $annaAndCara, $this->resolve(CompetitionTag::lineup($newLineup)));
    }

    public function testALineupNoRoundHasAnyMoreIsUnknown(): void
    {
        self::assertTarget(false, F::ROUND_PAIRS_FINAL, null, $this->resolve(CompetitionTag::lineup(self::UNKNOWN)));

        // Ben removed from the event: the pair Anna + Ben exists no more (B §2.11: a re-added Ben is a new participant)
        $sharks = CompetitionLineupId::of(F::COMPETITION_RESULTS_CUP, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]);
        $this->connection()->executeStatement('UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => F::PARTICIPANT_BEN]);
        self::assertTarget(false, F::ROUND_PAIRS_FINAL, null, $this->resolve(CompetitionTag::lineup($sharks)));
    }

    public function testAnEventWithoutRoundsHasNoRound(): void
    {
        $target = $this->resolver->resolve(CompetitionFixture::COMPETITION_UNAPPROVED, CompetitionTag::table(1));

        self::assertFalse($target->known);
        self::assertNull($target->round);
        self::assertNull($target->entry);
    }

    public function testKnowsWithoutPickingARound(): void
    {
        $sharks = CompetitionLineupId::of(F::COMPETITION_RESULTS_CUP, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]);

        self::assertTrue($this->resolver->knows(F::COMPETITION_RESULTS_CUP, CompetitionTag::participant(F::PARTICIPANT_ANNA)));
        self::assertTrue($this->resolver->knows(F::COMPETITION_RESULTS_CUP, CompetitionTag::lineup($sharks)));
        self::assertTrue($this->resolver->knows(F::COMPETITION_RESULTS_CUP, CompetitionTag::entry(F::ENTRY_B_HUGO)));
        // A table always has a page: who sits there changes by the round
        self::assertTrue($this->resolver->knows(F::COMPETITION_RESULTS_CUP, CompetitionTag::table(999)));

        self::assertFalse($this->resolver->knows(F::COMPETITION_RESULTS_CUP, CompetitionTag::participant(self::UNKNOWN)));
        self::assertFalse($this->resolver->knows(F::COMPETITION_RESULTS_CUP, CompetitionTag::lineup(self::UNKNOWN)));
        self::assertFalse($this->resolver->knows(F::COMPETITION_RESULTS_CUP, CompetitionTag::entry(self::UNKNOWN)));
    }

    private function resolve(CompetitionTag $tag): CompetitionTagTarget
    {
        return $this->resolver->resolve(F::COMPETITION_RESULTS_CUP, $tag);
    }

    private static function assertTarget(bool $known, string $roundId, null|string $entry, CompetitionTagTarget $target): void
    {
        self::assertSame(
            ['known' => $known, 'round' => $roundId, 'entry' => $entry],
            ['known' => $target->known, 'round' => $target->round?->roundId, 'entry' => $target->entry?->toString()],
        );
    }

    private function startStopwatch(string $roundId, string $startedAgo): void
    {
        $this->connection()->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'running', stopwatch_started_at = :startedAt WHERE id = :id",
            ['startedAt' => TestClock::now()->modify($startedAgo)->format('Y-m-d H:i:s'), 'id' => $roundId],
        );
    }

    /**
     * @param list<string> $participantIds
     */
    private function addTeam(string $roundId, array $participantIds): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $round = $entityManager->find(CompetitionRound::class, $roundId);
        self::assertNotNull($round);
        $team = new CompetitionTeam(Uuid::uuid7(), $round);
        $entityManager->persist($team);

        foreach ($participantIds as $participantId) {
            $participant = $entityManager->find(CompetitionParticipant::class, $participantId);
            self::assertNotNull($participant);
            $entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round, $team));
        }

        $entityManager->flush();
        $entityManager->clear();

        return $team->id->toString();
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
