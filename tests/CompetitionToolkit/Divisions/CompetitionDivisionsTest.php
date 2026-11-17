<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\Divisions;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DivisionsInUse;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionDivisions;
use SpeedPuzzling\Web\Message\AdvanceQualified;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Message\SetCompetitionDivisions;
use SpeedPuzzling\Web\Message\SetRoundEntryDivisions;
use SpeedPuzzling\Web\Query\GetCompetitionDivisions;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Results\AdvancementPlan;
use SpeedPuzzling\Web\Results\CompetitionDivisionOverview;
use SpeedPuzzling\Web\Results\RoundDivision;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\DivisionInput;
use SpeedPuzzling\Web\Value\EntryDivisionInput;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Divisions (shared-puzzles-and-divisions.md D1 + D2): the event's list and each round's subset in one write with its
 * refusals, entries' divisions, the rank within a division (A13), advancement copying it (A11), duplicate, delete.
 */
final class CompetitionDivisionsTest extends KernelTestCase
{
    use DivisionsSetup;

    private const string CUP = OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testStPaulsListAndEachRoundsSubsetAreSaved(): void
    {
        $this->setUpStPaulDivisions($this->messageBus, withEntries: false);

        $divisions = self::getContainer()->get(GetCompetitionDivisions::class)->ofCompetition(self::CUP);

        self::assertSame([
            ['Regular', null, 0, [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_PAIRS]],
            ['Senior 55+', 'S', 1, [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_FINAL, OfficialResultsFixture::ROUND_PAIRS]],
            ['Junior U16', 'J', 2, [OfficialResultsFixture::ROUND_GROUP_A]],
        ], array_map(static fn (CompetitionDivisionOverview $division): array => [
            $division->name,
            $division->shortName,
            $division->position,
            self::sortedRounds($division->roundIds),
        ], $divisions));

        $overviews = self::getContainer()->get(GetRoundResultsOverview::class);
        self::assertSame(['Regular', 'Senior 55+', 'Junior U16'], array_map(static fn (RoundDivision $division): string => $division->name, $overviews->forRound(OfficialResultsFixture::ROUND_GROUP_A)->divisions));
        // A round without divisions: its JSON has no key
        self::assertArrayNotHasKey('divisions', $overviews->forRound(OfficialResultsFixture::ROUND_GROUP_B)->jsonSerialize());
    }

    public function testEntriesRankWithinTheirDivisionBesideTheOverallRank(): void
    {
        $this->setUpStPaulDivisions($this->messageBus);

        $entries = self::byName(self::getContainer()->get(GetRoundResultEntries::class)->forRound(OfficialResultsFixture::ROUND_GROUP_A));

        // name => [overall rank, division, division rank]
        self::assertSame([
            'Anna' => [1, 'Regular', 1],
            'Ben' => [2, 'Senior 55+', 1],
            'Cara' => [2, 'Senior 55+', 1],
            'Dan' => [4, 'Senior 55+', 3],
            'Eva' => [null, 'Junior U16', null],
            'Filip' => [null, null, null],
        ], array_map(static fn (RoundResultEntry $entry): array => [$entry->rank, $entry->division?->name, $entry->divisionRank], $entries));

        $json = $entries['Ben']->jsonSerialize();
        self::assertSame(['id' => $this->senior, 'name' => 'Senior 55+', 'shortName' => 'S'], json_decode((string) json_encode($json['division']), true));
        self::assertSame(1, $json['divisionRank']);
        self::assertArrayHasKey('division', $entries['Filip']->jsonSerialize());

        // A round without divisions keeps its entries' shape
        foreach (self::getContainer()->get(GetRoundResultEntries::class)->forRound(OfficialResultsFixture::ROUND_GROUP_B) as $entry) {
            self::assertFalse($entry->divisionsUsed);
            self::assertArrayNotHasKey('division', $entry->jsonSerialize());
            self::assertArrayNotHasKey('divisionRank', $entry->jsonSerialize());
        }
    }

    public function testAPairCarriesTheDivisionNotItsMembers(): void
    {
        $this->setUpStPaulDivisions($this->messageBus, withEntries: false);

        $changed = self::handled($this->messageBus, new SetRoundEntryDivisions(self::CUP, OfficialResultsFixture::ROUND_PAIRS, [
            new EntryDivisionInput(null, OfficialResultsFixture::TEAM_SHARKS, 'Senior 55+'),
            // A member stands for their pair
            new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_GINA, null, 'Regular'),
            new EntryDivisionInput(null, OfficialResultsFixture::TEAM_CORNERS, 'S'),
        ]));

        self::assertSame(3, $changed);
        self::assertSame($this->senior, $this->database->fetchOne('SELECT division_id FROM competition_team WHERE id = ?', [OfficialResultsFixture::TEAM_SHARKS]));
        self::assertSame($this->regular, $this->database->fetchOne('SELECT division_id FROM competition_team WHERE id = ?', [OfficialResultsFixture::TEAM_EDGES]));
        self::assertSame(0, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_participant_round WHERE round_id = ? AND division_id IS NOT NULL', [OfficialResultsFixture::ROUND_PAIRS]));

        $entries = self::byName(self::getContainer()->get(GetRoundResultEntries::class)->forRound(OfficialResultsFixture::ROUND_PAIRS));
        self::assertSame([1, 1], [$entries['Puzzle Sharks']->rank, $entries['Puzzle Sharks']->divisionRank]);
        // Corner Pieces did not finish: third overall, second of the seniors behind Puzzle Sharks
        self::assertSame([3, 2], [$entries['Corner Pieces']->rank, $entries['Corner Pieces']->divisionRank]);
        self::assertSame([2, 1], [$entries['Edge Hunters']->rank, $entries['Edge Hunters']->divisionRank]);

        // Setting the same again changes nothing
        self::assertSame(0, self::handled($this->messageBus, new SetRoundEntryDivisions(self::CUP, OfficialResultsFixture::ROUND_PAIRS, [
            new EntryDivisionInput(null, OfficialResultsFixture::TEAM_SHARKS, $this->senior),
        ])));
    }

    public function testEntryDivisionsAreAllOrNothing(): void
    {
        $this->setUpStPaulDivisions($this->messageBus, withEntries: false);

        $cases = [
            'not_in_round' => [OfficialResultsFixture::ROUND_PAIRS, [new EntryDivisionInput(null, OfficialResultsFixture::TEAM_SHARKS, 'Junior U16')]],
            'unknown_division' => [OfficialResultsFixture::ROUND_GROUP_A, [new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_ANNA, null, 'Masters')]],
            // Gina is a member of Edge Hunters - the pair twice
            'duplicate_entry' => [OfficialResultsFixture::ROUND_PAIRS, [
                new EntryDivisionInput(null, OfficialResultsFixture::TEAM_EDGES, 'Regular'),
                new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_GINA, null, 'Senior 55+'),
            ]],
            // Hugo is in Group B, not in Group A
            'unknown_entry' => [OfficialResultsFixture::ROUND_GROUP_A, [
                new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_ANNA, null, 'Regular'),
                new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_HUGO, null, 'Regular'),
            ]],
        ];

        foreach ($cases as $reason => [$roundId, $entries]) {
            $refused = self::refusalOf(fn () => $this->messageBus->dispatch(new SetRoundEntryDivisions(self::CUP, $roundId, $entries)));
            self::assertInstanceOf(InvalidCompetitionDivisions::class, $refused, $reason);
            self::assertSame($reason, $refused->reason);
        }

        self::assertSame(0, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_participant_round WHERE division_id IS NOT NULL'));
        self::assertSame(0, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_team WHERE division_id IS NOT NULL'));
    }

    public function testARemovedParticipantsEntryIsNoEntry(): void
    {
        $this->setUpStPaulDivisions($this->messageBus, withEntries: false);
        $this->database->executeStatement('UPDATE competition_participant SET deleted_at = NOW() WHERE id = ?', [OfficialResultsFixture::PARTICIPANT_FILIP]);

        $refused = self::refusalOf(fn () => $this->messageBus->dispatch(new SetRoundEntryDivisions(self::CUP, OfficialResultsFixture::ROUND_GROUP_A, [
            new EntryDivisionInput(OfficialResultsFixture::PARTICIPANT_FILIP, null, 'Regular'),
        ])));

        self::assertInstanceOf(InvalidCompetitionDivisions::class, $refused);
        self::assertSame('unknown_entry', $refused->reason);
    }

    public function testTheListIsRefusedBeforeAnythingChanges(): void
    {
        $tooMany = array_map(static fn (int $index): DivisionInput => new DivisionInput(Uuid::uuid7()->toString(), 'Division ' . $index), range(1, 13));
        $otherEvents = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new SetCompetitionDivisions(CompetitionFixture::COMPETITION_WJPC_2024, [new DivisionInput($otherEvents, 'Elsewhere')], []));

        $cases = [
            'too_many' => [$tooMany, []],
            'duplicate_name' => [[new DivisionInput(Uuid::uuid7()->toString(), 'Senior'), new DivisionInput(Uuid::uuid7()->toString(), ' senior ')], []],
            'invalid_name' => [[new DivisionInput(Uuid::uuid7()->toString(), '   ')], []],
            'invalid_short_name' => [[new DivisionInput(Uuid::uuid7()->toString(), 'Senior', 'Seniors 55 plus')], []],
            'unknown_division' => [[new DivisionInput($otherEvents, 'Taken over')], []],
            'unknown_round' => [[new DivisionInput($id = Uuid::uuid7()->toString(), 'Senior')], [CompetitionFixture::COMPETITION_WJPC_2024 => [$id]]],
        ];

        foreach ($cases as $reason => [$divisions, $rounds]) {
            $refused = self::refusalOf(fn () => $this->messageBus->dispatch(new SetCompetitionDivisions(self::CUP, $divisions, $rounds)));
            self::assertInstanceOf(InvalidCompetitionDivisions::class, $refused, $reason);
            self::assertSame($reason, $refused->reason);
        }

        self::assertSame(0, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_division WHERE competition_id = ?', [self::CUP]));
        self::assertSame('Elsewhere', $this->database->fetchOne('SELECT name FROM competition_division WHERE id = ?', [$otherEvents]));
    }

    public function testTakingADivisionFromEntriesAsksFirst(): void
    {
        $this->setUpStPaulDivisions($this->messageBus);

        // Senior goes from Group A only (kept in the list) - Ben, Cara and Dan would lose it
        $without = new SetCompetitionDivisions(self::CUP, [
            new DivisionInput($this->regular, 'Regular'),
            new DivisionInput($this->senior, 'Senior 55+', 'S'),
            new DivisionInput($this->junior, 'Junior U16', 'J'),
        ], [
            OfficialResultsFixture::ROUND_GROUP_A => [$this->regular, $this->junior],
        ]);

        $inUse = self::refusalOf(fn () => $this->messageBus->dispatch($without));
        self::assertInstanceOf(DivisionsInUse::class, $inUse);
        self::assertSame(3, $inUse->count);

        self::assertSame(3, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_participant_round WHERE division_id = ?', [$this->senior]));
        self::assertSame(6, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_round_division crd JOIN competition_division d ON d.id = crd.division_id WHERE d.competition_id = ?', [self::CUP]));

        $changedRounds = self::handled($this->messageBus, new SetCompetitionDivisions(self::CUP, $without->divisions, $without->rounds, confirmClearing: true));

        self::assertIsArray($changedRounds);
        self::assertSame(self::sortedRounds([OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_PAIRS, OfficialResultsFixture::ROUND_FINAL]), self::sortedRounds($changedRounds));
        self::assertSame(0, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_participant_round WHERE division_id = ?', [$this->senior]));
        self::assertSame($this->regular, $this->database->fetchOne('SELECT division_id FROM competition_participant_round WHERE id = ?', [OfficialResultsFixture::ENTRY_A_ANNA]));
        // The division stays on the list
        self::assertSame(3, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_division WHERE competition_id = ?', [self::CUP]));

        // Removing Junior from the list takes it from Eva - confirmed
        $this->messageBus->dispatch(new SetCompetitionDivisions(self::CUP, [new DivisionInput($this->regular, 'Regular (open)')], [
            OfficialResultsFixture::ROUND_GROUP_A => [$this->regular],
        ], confirmClearing: true));

        self::assertSame(['Regular (open)'], $this->database->fetchFirstColumn('SELECT name FROM competition_division WHERE competition_id = ?', [self::CUP]));
        self::assertSame([$this->regular], $this->database->fetchFirstColumn('SELECT DISTINCT division_id FROM competition_participant_round WHERE division_id IS NOT NULL'));
    }

    public function testAnAdvancedEntryKeepsItsDivisionWhenTheTargetUsesIt(): void
    {
        $this->setUpStPaulDivisions($this->messageBus);

        $advance = static fn (bool $dryRun, null|string $planHash): AdvanceQualified => new AdvanceQualified(
            competitionId: self::CUP,
            sourceRoundIds: [OfficialResultsFixture::ROUND_GROUP_A],
            targetRoundIds: [OfficialResultsFixture::ROUND_FINAL],
            distribution: AdvanceDistribution::Single,
            dryRun: $dryRun,
            planHash: $planHash,
        );

        $plan = self::handled($this->messageBus, $advance(true, null));
        self::assertInstanceOf(AdvancementPlan::class, $plan);
        $this->messageBus->dispatch($advance(false, $plan->planHash));

        // Ben (Senior, qualified) advanced into the Final, which uses Senior
        self::assertSame($this->senior, $this->database->fetchOne(
            'SELECT division_id FROM competition_participant_round WHERE round_id = ? AND participant_id = ?',
            [OfficialResultsFixture::ROUND_FINAL, OfficialResultsFixture::PARTICIPANT_BEN],
        ));
    }

    public function testTheDuplicateCopiesTheListAndEachRoundsSubsetButNoEntry(): void
    {
        $this->setUpStPaulDivisions($this->messageBus);
        $copyId = Uuid::uuid7();

        $this->messageBus->dispatch(new DuplicateCompetition($copyId, self::CUP, PlayerFixture::PLAYER_WITH_STRIPE));

        $copied = self::getContainer()->get(GetCompetitionDivisions::class)->ofCompetition($copyId->toString());
        self::assertSame(['Regular' => 2, 'Senior 55+' => 3, 'Junior U16' => 1], array_combine(
            array_map(static fn (CompetitionDivisionOverview $division): string => $division->name, $copied),
            array_map(static fn (CompetitionDivisionOverview $division): int => count($division->roundIds), $copied),
        ));
        self::assertSame([0, 0, 0], array_map(static fn (CompetitionDivisionOverview $division): int => $division->entriesCount, $copied));
        self::assertSame('S', $copied[1]->shortName);
    }

    public function testDeletingTheEventTakesItsDivisions(): void
    {
        $this->setUpStPaulDivisions($this->messageBus);

        $this->messageBus->dispatch(new DeleteCompetition(self::CUP));

        self::assertSame(0, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_division WHERE competition_id = ?', [self::CUP]));
        self::assertSame(0, self::countOf($this->database, 'SELECT COUNT(*) FROM competition_round_division WHERE division_id IN (?, ?, ?)', [$this->regular, $this->senior, $this->junior]));
    }

    /**
     * @param list<RoundResultEntry> $entries
     * @return array<string, RoundResultEntry> in name order
     */
    private static function byName(array $entries): array
    {
        $byName = [];
        foreach ($entries as $entry) {
            // A person by their first name ("Anna Fast" → Anna), a pair by its name
            $byName[$entry->kind === RoundResultEntry::KIND_PERSON ? explode(' ', $entry->displayName())[0] : $entry->displayName()] = $entry;
        }

        ksort($byName);

        return $byName;
    }

    /**
     * @param array<mixed> $roundIds
     * @return list<string>
     */
    private static function sortedRounds(array $roundIds): array
    {
        $roundIds = array_map(static fn (mixed $id): string => strtolower((string) (is_scalar($id) ? $id : '')), array_values($roundIds));
        sort($roundIds);

        return $roundIds;
    }
}
