<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\DeleteCompetitionStanding;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Message\EditCompetitionStanding;
use SpeedPuzzling\Web\Message\MoveRoundToCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Results\StandingRow;
use SpeedPuzzling\Web\Results\StandingTable;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;
use SpeedPuzzling\Web\Value\StandingScoreStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Standings (competition toolkit A6): the write messages and their refusals, the organiser's computed view over a
 * World-Series-like event (6 solo + 6 pair rounds, sum per category), pruning on round delete/move, duplicate copies,
 * and the event delete cascade (R-review-and-plan.md §2.10).
 */
final class CompetitionStandingsTest extends KernelTestCase
{
    use WorldSeriesSetup;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->createWorldSeries(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testTheSoloStandingSumsAllSixRoundsWithTheDefaultRules(): void
    {
        $standingId = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds);

        $table = $this->table($standingId);

        self::assertSame(6, $table->roundsTotal());
        self::assertSame(6, $table->roundsCounted());
        // Rounds 4-6 are not published - the organiser's view includes them, provisional
        self::assertTrue($table->isProvisional());
        self::assertSame(['Alice' => [1, 6000], 'Cyril' => [2, 6600], 'Bob' => [3, 8100], 'Dora' => [null, 4000]], self::rows($table->rows));

        $bob = $table->rows[2];
        self::assertSame(5, $bob->roundsFinished);
        self::assertSame(StandingScoreStatus::Unfinished, $bob->perRound[$this->soloRounds[5]]->status);
        self::assertSame(3600, $bob->perRound[$this->soloRounds[5]]->scoreSeconds);

        $dora = $table->rows[3];
        self::assertFalse($dora->complete);
        self::assertSame(5, $dora->roundsScored);
        self::assertSame(StandingScoreStatus::DidNotStart, $dora->perRound[$this->soloRounds[3]]->status);
        // The round's own official place comes along: Dora is the fastest of round 1
        self::assertSame(1, $dora->perRound[$this->soloRounds[0]]->rank);
    }

    public function testThePenaltyPerMissingPieceAndTotalOnlyChangeTheOrder(): void
    {
        $standingId = $this->create('Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules(2, StandingOrder::TotalOnly));

        // Bob: 4500 + 3600 + 50 missing pieces × 2 s
        self::assertSame(['Alice' => [1, 6000], 'Cyril' => [2, 6600], 'Bob' => [3, 8200], 'Dora' => [null, 4000]], self::rows($this->table($standingId)->rows));

        // Only rounds where somebody did not finish need a limit and pieces - round 6 has both
        self::assertSame([], $this->table($standingId)->roundsWithoutPieces());
        self::assertSame([], $this->table($standingId)->roundsWithoutLimit());
        $this->database->executeStatement('UPDATE competition_round SET minutes_limit = NULL WHERE id = :round', ['round' => $this->soloRounds[5]]);
        $this->database->executeStatement('DELETE FROM competition_round_puzzle WHERE round_id = :round', ['round' => $this->soloRounds[5]]);
        $table = $this->table($standingId);
        self::assertSame([$this->soloRounds[5]], array_map(static fn ($round): string => $round->id, $table->roundsWithoutLimit()));
        self::assertSame([$this->soloRounds[5]], array_map(static fn ($round): string => $round->id, $table->roundsWithoutPieces()));
        // Without a limit Bob's unfinished round cannot score - he is incomplete now
        self::assertSame(['Alice' => [1, 6000], 'Cyril' => [2, 6600], 'Dora' => [null, 4000], 'Bob' => [null, 4500]], self::rows($table->rows));
        $this->database->executeStatement('UPDATE competition_round SET minutes_limit = 60 WHERE id = :round', ['round' => $this->soloRounds[5]]);

        // Only the three published-in-the-middle rounds: Bob is fastest in rounds 1-3
        $this->messageBus->dispatch(new EditCompetitionStanding($standingId, self::WS_COMPETITION, 'Solo 1-3', RoundCategory::Solo, array_slice($this->soloRounds, 0, 3), new StandingRules()));
        $table = $this->table($standingId);
        self::assertSame('Solo 1-3', $table->standing->name);
        self::assertSame(['Dora' => [1, 2400], 'Bob' => [2, 2700], 'Alice' => [3, 3000], 'Cyril' => [4, 3300]], self::rows($table->rows));
        self::assertFalse($table->isProvisional());
    }

    public function testPairsAreSummedPerExactLineUp(): void
    {
        $standingId = $this->create('World Series - Pairs', RoundCategory::Duo, $this->pairRounds);

        $table = $this->table($standingId);
        self::assertSame(['Alice & Bob' => [1, 12000], 'Team Cyril' => [null, 9000]], array_slice(self::rows($table->rows), 0, 2, true));
        self::assertCount(3, $table->rows);

        $keys = array_map(static fn (StandingRow $row): string => $row->key, $table->rows);
        self::assertSame([
            'l:' . CompetitionLineupId::of(self::WS_COMPETITION, [$this->people['Alice'], $this->people['Bob']]),
            'l:' . CompetitionLineupId::of(self::WS_COMPETITION, [$this->people['Cyril'], $this->people['Dora']]),
            'l:' . CompetitionLineupId::of(self::WS_COMPETITION, [$this->people['Cyril'], $this->people['Eve']]),
        ], $keys);
        // The substitute's line-up: one round
        self::assertSame(1, $table->rows[2]->roundsScored);
        self::assertSame(['Cyril', 'Eve'], array_map(static fn ($entrant): string => $entrant->name, $table->rows[2]->entrants));
    }

    public function testARoundWithNothingRecordedDoesNotCountYet(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = NULL, result_pieces_placed = NULL, result_did_not_start = false WHERE round_id = :round',
            ['round' => $this->soloRounds[5]],
        );
        $standingId = $this->create('Solo', RoundCategory::Solo, $this->soloRounds);

        $table = $this->table($standingId);
        self::assertSame(5, $table->roundsCounted());
        self::assertTrue($table->isProvisional());
        // Nobody misses round 6 - over the five rounds that count Bob finished everything and is the fastest
        self::assertSame(['Bob' => [1, 4500], 'Alice' => [2, 5000], 'Cyril' => [3, 5500], 'Dora' => [null, 3200]], self::rows($table->rows));
        self::assertSame(StandingScoreStatus::NotCounted, $table->rows[0]->perRound[$this->soloRounds[5]]->status);
    }

    public function testTheWritesRefuseRoundsOfAnotherEventOrCategoryAndNoRounds(): void
    {
        $this->assertRefused('unknown_round', fn () => $this->create('X', RoundCategory::Solo, [OfficialResultsFixture::ROUND_GROUP_A]));
        $this->assertRefused('wrong_category', fn () => $this->create('X', RoundCategory::Solo, [$this->soloRounds[0], $this->pairRounds[0]]));
        $this->assertRefused('no_rounds', fn () => $this->create('X', RoundCategory::Solo, []));
        $this->assertRefused('invalid_name', fn () => $this->create('   ', RoundCategory::Solo, [$this->soloRounds[0]]));

        $standingId = $this->create('Solo', RoundCategory::Solo, $this->soloRounds);
        $this->assertRefused('wrong_category', fn () => $this->messageBus->dispatch(new EditCompetitionStanding($standingId, self::WS_COMPETITION, 'Solo', RoundCategory::Duo, $this->soloRounds, new StandingRules())));

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM competition_standing WHERE competition_id = :id', self::WS_COMPETITION));
    }

    public function testARepeatedRoundIsKeptOnceAndSlugsStayUniqueAndKeptOnRename(): void
    {
        $first = $this->create('Overall', RoundCategory::Solo, [$this->soloRounds[1], $this->soloRounds[0], $this->soloRounds[1]]);
        $second = $this->create('Overall', RoundCategory::Duo, $this->pairRounds);
        $standings = self::getContainer()->get(GetCompetitionStandings::class);

        self::assertSame([$this->soloRounds[1], $this->soloRounds[0]], $standings->byId($first)->roundIds);
        self::assertSame('overall', $standings->byId($first)->slug);
        self::assertSame('overall-2', $standings->byId($second)->slug);
        self::assertSame([0, 1], array_map(static fn ($standing): int => $standing->position, $standings->ofCompetition(self::WS_COMPETITION)));

        $this->messageBus->dispatch(new EditCompetitionStanding($first, self::WS_COMPETITION, 'Renamed', RoundCategory::Solo, $this->soloRounds, new StandingRules()));
        self::assertSame('overall', $standings->byId($first)->slug);
    }

    public function testAStandingOfAnotherEventIsNotFound(): void
    {
        $standingId = $this->create('Solo', RoundCategory::Solo, $this->soloRounds);

        $this->assertNotFound(fn () => $this->messageBus->dispatch(new EditCompetitionStanding($standingId, OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules())));
        $this->assertNotFound(fn () => $this->messageBus->dispatch(new DeleteCompetitionStanding($standingId, OfficialResultsFixture::COMPETITION_RESULTS_CUP)));

        $this->messageBus->dispatch(new DeleteCompetitionStanding($standingId, self::WS_COMPETITION));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM competition_standing WHERE competition_id = :id', self::WS_COMPETITION));
        // Only the standing - the rounds stay
        self::assertSame(12, $this->countRows('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id', self::WS_COMPETITION));
    }

    public function testDeletingARoundPrunesItFromTheStandings(): void
    {
        $solo = $this->create('Solo', RoundCategory::Solo, $this->soloRounds);
        $pairs = $this->create('Pairs', RoundCategory::Duo, $this->pairRounds);

        $this->messageBus->dispatch(new DeleteCompetitionRound($this->soloRounds[2], self::WS_COMPETITION));

        $standings = self::getContainer()->get(GetCompetitionStandings::class);
        self::assertSame([$this->soloRounds[0], $this->soloRounds[1], $this->soloRounds[3], $this->soloRounds[4], $this->soloRounds[5]], $standings->byId($solo)->roundIds);
        self::assertSame($this->pairRounds, $standings->byId($pairs)->roundIds);
        self::assertSame(5, $this->table($solo)->roundsTotal());
    }

    public function testMovingAnEmptyRoundAwayPrunesItFromTheStandings(): void
    {
        // An empty round may move (one with entries is refused) - add one to the standing first
        $this->database->executeStatement('DELETE FROM competition_participant_round WHERE round_id = :round', ['round' => $this->soloRounds[0]]);
        $solo = $this->create('Solo', RoundCategory::Solo, $this->soloRounds);

        $this->messageBus->dispatch(new MoveRoundToCompetition($this->soloRounds[0], self::WS_COMPETITION, OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_ADMIN));

        self::assertSame(array_slice($this->soloRounds, 1), self::getContainer()->get(GetCompetitionStandings::class)->byId($solo)->roundIds);
    }

    public function testARoundWhoseCategoryChangedIsSkippedOnRead(): void
    {
        $solo = $this->create('Solo', RoundCategory::Solo, $this->soloRounds);
        $this->database->executeStatement("UPDATE competition_round SET category = 'team' WHERE id = :round", ['round' => $this->soloRounds[5]]);

        $table = $this->table($solo);
        self::assertSame(5, $table->roundsTotal());
        self::assertSame(1, $table->skippedRounds);
    }

    public function testDuplicatingTheEventCopiesItsStandingsUnpublishedWithTheCopiedRounds(): void
    {
        $solo = $this->create('World Series - Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules(5, StandingOrder::TotalOnly));
        $this->database->executeStatement('UPDATE competition_standing SET published_at = NOW() WHERE id = :id', ['id' => $solo]);
        $this->create('World Series - Pairs', RoundCategory::Duo, $this->pairRounds);

        $copyId = Uuid::uuid7();
        $this->messageBus->dispatch(new DuplicateCompetition(
            sourceCompetitionId: self::WS_COMPETITION,
            newCompetitionId: $copyId,
            actingPlayerId: self::WS_ORGANISER,
        ));

        $copies = self::getContainer()->get(GetCompetitionStandings::class)->ofCompetition($copyId->toString());
        self::assertCount(2, $copies);
        self::assertSame(['World Series - Solo', 'World Series - Pairs'], array_map(static fn ($standing): string => $standing->name, $copies));
        self::assertNull($copies[0]->publishedAt);
        self::assertSame(5, $copies[0]->rules->penaltySecondsPerPiece);
        self::assertSame(StandingOrder::TotalOnly, $copies[0]->rules->order);
        self::assertSame(RoundCategory::Duo, $copies[1]->category);

        // Remapped onto the copy's rounds, in the same order (the copied rounds keep the slugs)
        /** @var array<string, string> $copiedRoundSlugs */
        $copiedRoundSlugs = $this->database->fetchAllKeyValue('SELECT id, slug FROM competition_round WHERE competition_id = :id', ['id' => $copyId->toString()]);
        self::assertSame(['solo-1', 'solo-2', 'solo-3', 'solo-4', 'solo-5', 'solo-6'], array_map(static fn (string $id): string => $copiedRoundSlugs[$id], $copies[0]->roundIds));
        self::assertSame(['pairs-1', 'pairs-2', 'pairs-3', 'pairs-4', 'pairs-5', 'pairs-6'], array_map(static fn (string $id): string => $copiedRoundSlugs[$id], $copies[1]->roundIds));

        // The source keeps its own
        self::assertCount(2, self::getContainer()->get(GetCompetitionStandings::class)->ofCompetition(self::WS_COMPETITION));
    }

    public function testDeletingTheEventDeletesItsStandings(): void
    {
        $this->create('Solo', RoundCategory::Solo, $this->soloRounds);

        $this->messageBus->dispatch(new DeleteCompetition(self::WS_COMPETITION));

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM competition_standing WHERE competition_id = :id', self::WS_COMPETITION));
    }

    /**
     * @param list<string> $roundIds
     */
    private function create(string $name, RoundCategory $category, array $roundIds, StandingRules $rules = new StandingRules()): string
    {
        $standingId = Uuid::uuid7();
        $this->messageBus->dispatch(new CreateCompetitionStanding($standingId, self::WS_COMPETITION, $name, $category, $roundIds, $rules));

        return $standingId->toString();
    }

    private function table(string $standingId): StandingTable
    {
        $standing = self::getContainer()->get(GetCompetitionStandings::class)->byId($standingId);

        return self::getContainer()->get(GetStandingResults::class)->organiserView($standing);
    }

    /**
     * @param list<StandingRow> $rows
     * @return array<string, array{null|int, int}>
     */
    private static function rows(array $rows): array
    {
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->name] ??= [$row->rank, $row->totalSeconds];
        }

        return $byName;
    }

    private function assertRefused(string $reason, Closure $write): void
    {
        try {
            $write();
            self::fail('Expected the standing to be refused: ' . $reason);
        } catch (HandlerFailedException $failed) {
            $previous = $failed->getPrevious();
            self::assertInstanceOf(InvalidCompetitionStanding::class, $previous);
            self::assertSame($reason, $previous->reason);
        } catch (InvalidCompetitionStanding $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function assertNotFound(Closure $write): void
    {
        try {
            $write();
            self::fail('Expected CompetitionStandingNotFound');
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf(CompetitionStandingNotFound::class, $failed->getPrevious());
        } catch (CompetitionStandingNotFound) {
            self::addToAssertionCount(1);
        }
    }

    private function countRows(string $sql, string $id): int
    {
        $count = $this->database->fetchOne($sql, ['id' => $id]);

        return is_numeric($count) ? (int) $count : 0;
    }
}
