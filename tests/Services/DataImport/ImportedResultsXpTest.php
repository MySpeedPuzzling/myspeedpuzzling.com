<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultsFollowUp;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultWriter;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\ImportedResult;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultOutcome;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\XpReason;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What an import does to XP ledgers that exist (docs/features/data-import/writer.md "XP"): a player with a history -
 * entries of typed results, a settled bonus, the audit entries of a deleted solve - imports results, one of them with
 * a registered partner who has a ledger of their own. Nothing either of them had changes; the imported results earn
 * the history formula (README Q4): the base, no bonuses.
 */
final class ImportedResultsXpTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string PLAYER_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string PARTNER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string PARTNER_CODE = '#player3';

    private MessageBusInterface $bus;
    private Connection $database;
    private EntityManagerInterface $entityManager;
    private InMemoryTransport $asyncTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->asyncTransport = $transport;
    }

    public function testAnImportLeavesEveryExistingLedgerEntryAloneAndEarnsTheHistoryFormula(): void
    {
        // Fixtures carry results without a ledger - both start from their full replay
        foreach ([self::PLAYER, self::PARTNER] as $playerId) {
            $this->bus->dispatch(new RecalculateXpForPlayer($playerId), [new TransportNamesStamp(['sync'])]);
        }

        // The audit entries of a deleted solve: the player deletes a result that earned
        $this->entityManager->clear();
        $this->asyncTransport->reset();
        $this->bus->dispatch(new DeletePuzzleSolvingTime(self::PLAYER_USER, PuzzleSolvingTimeFixture::TIME_01));
        $this->drain();
        self::assertGreaterThan(0, $this->entries(self::PLAYER, XpReason::SolveCompensation));

        // A bonus settled later, for each of them - on a result that stays
        foreach ([self::PLAYER, self::PARTNER] as $playerId) {
            $this->database->executeStatement(
                "INSERT INTO xp_entry (id, player_id, amount, reason, in_weekly_delta, earned_at, created_at, solving_time_id)
                SELECT :id, :player, 7, 'speed_settlement', false, :now, :now, (SELECT solving_time_id FROM xp_entry WHERE player_id = :player AND reason = 'solve_base' ORDER BY id LIMIT 1)",
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'now' => TestClock::FROZEN_NOW],
            );
            $this->database->executeStatement('UPDATE player SET xp_total = xp_total + 7 WHERE id = :player', ['player' => $playerId]);
        }

        $before = [self::PLAYER => $this->ledger(self::PLAYER), self::PARTNER => $this->ledger(self::PARTNER)];
        self::assertGreaterThan(3, count($before[self::PLAYER]));
        self::assertGreaterThan(1, count($before[self::PARTNER]));

        // The import: two old solves (1 June 2026 - before the full formula started) of puzzles neither has a result
        // of, one alone and one with the registered partner
        [$soloPuzzle, $pairPuzzle] = $this->puzzlesNobodySolved();
        $this->entityManager->clear();
        $this->asyncTransport->reset();

        $solo = $this->import($soloPuzzle, [], position: 2);
        $pair = $this->import($pairPuzzle, [self::PARTNER_CODE], position: 3);

        $this->entityManager->wrapInTransaction(function () use ($solo, $pair): void {
            self::getContainer()->get(ImportedResultsFollowUp::class)->afterResultsAdded([$solo, $pair]);
        });

        // Never the full rebuild - it would wipe the settled bonuses and the audit entries
        foreach ($this->asyncTransport->getSent() as $envelope) {
            self::assertNotInstanceOf(RecalculateXpForPlayer::class, $envelope->getMessage());
        }

        $this->drain();

        foreach ([self::PLAYER => [$solo, $pair], self::PARTNER => [$pair]] as $playerId => $outcomes) {
            $after = $this->ledger($playerId);
            $importedIds = array_map(static fn (ImportedResultOutcome $outcome): string => (string) $outcome->solvingTimeId, $outcomes);

            $kept = array_values(array_filter($after, static fn (array $entry): bool => in_array($entry['solving_time_id'], $importedIds, true) === false));
            $earned = array_values(array_filter($after, static fn (array $entry): bool => in_array($entry['solving_time_id'], $importedIds, true)));

            // Every entry that was there is there, as it was: id, amount, reason, moment
            self::assertSame($before[$playerId], $kept, 'An existing ledger entry changed for ' . $playerId);

            // Each imported result: the base, once, and nothing else - no speed or difficulty bonus, no weekly boost
            self::assertSame(
                array_fill(0, count($outcomes), XpReason::SolveBase->value),
                array_column($earned, 'reason'),
            );

            foreach ($earned as $entry) {
                self::assertGreaterThan(0, $entry['amount']);
                self::assertIsString($entry['earned_at']);
                self::assertStringStartsWith('2026-06-01', $entry['earned_at']);
            }

            // The player's total is the ledger's
            self::assertSame(
                array_sum(array_map(static fn (array $entry): int => is_int($entry['amount']) ? $entry['amount'] : 0, $after)),
                $this->database->fetchOne('SELECT xp_total FROM player WHERE id = :id', ['id' => $playerId]),
            );
        }

        // The pair's result earns both of them the same
        self::assertSame(
            $this->database->fetchFirstColumn('SELECT amount FROM xp_entry WHERE solving_time_id = :id AND player_id = :player', ['id' => $pair->solvingTimeId, 'player' => self::PLAYER]),
            $this->database->fetchFirstColumn('SELECT amount FROM xp_entry WHERE solving_time_id = :id AND player_id = :player', ['id' => $pair->solvingTimeId, 'player' => self::PARTNER]),
        );
    }

    /**
     * @param list<string> $people
     */
    private function import(string $puzzleId, array $people, int $position): ImportedResultOutcome
    {
        $player = $this->entityManager->find(Player::class, self::PLAYER);
        self::assertNotNull($player);

        $import = DataImport::uploadOfFile(Uuid::uuid7(), $player, 'results.xlsx', DataImportFileKind::Xlsx, 1000, 'imports/' . Uuid::uuid7()->toString(), TestClock::now());
        $sheet = new DataImportSheet(Uuid::uuid7(), $import, 0, 'Results');
        $row = new DataImportRow(Uuid::uuid7(), $import, $sheet, $position, 1, 1);
        $sourceKey = '1:' . hash('sha256', $puzzleId);
        $row->sourceKey = $sourceKey;
        $this->entityManager->persist($import);
        $this->entityManager->persist($sheet);
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        $result = new ImportedResult(
            importRowId: $row->id->toString(),
            sourceKey: $sourceKey,
            playerId: self::PLAYER,
            puzzleId: $puzzleId,
            seconds: 5400,
            solvedDay: '2026-06-01',
            timezone: 'UTC',
            groupPlayers: $people,
            position: $position,
        );

        $outcome = $this->entityManager->wrapInTransaction(
            static fn (): ImportedResultOutcome => self::getContainer()->get(ImportedResultWriter::class)->write($result),
        );
        self::assertTrue($outcome->isAdded());

        return $outcome;
    }

    /**
     * @return array{string, string}
     */
    private function puzzlesNobodySolved(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn(
            'SELECT puzzle.id FROM puzzle
            WHERE puzzle.hide_until IS NULL AND puzzle.pieces_count BETWEEN 300 AND 2000
                AND NOT EXISTS (SELECT 1 FROM puzzle_solving_time time WHERE time.puzzle_id = puzzle.id)
            ORDER BY puzzle.id LIMIT 2',
        );
        self::assertCount(2, $ids);

        return [$ids[0], $ids[1]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledger(string $playerId): array
    {
        return $this->database->fetchAllAssociative(
            'SELECT id, solving_time_id, reason, amount, in_weekly_delta, earned_at FROM xp_entry WHERE player_id = :id ORDER BY id',
            ['id' => $playerId],
        );
    }

    private function entries(string $playerId, XpReason $reason): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM xp_entry WHERE player_id = :id AND reason = :reason', ['id' => $playerId, 'reason' => $reason->value]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * Handles the queued XP messages like the consumer: each on a fresh entity manager.
     */
    private function drain(): void
    {
        for ($pass = 0; $pass < 10; $pass++) {
            $envelopes = $this->asyncTransport->getSent();
            $this->asyncTransport->reset();
            $handled = false;

            foreach ($envelopes as $envelope) {
                $message = $envelope->getMessage();

                if (in_array($message::class, [AwardXpForSolvingTime::class, RecalculateXpChainForSolve::class, CompensateXpForDeletedSolve::class, RecalculateBadgesForPlayer::class], true) === false) {
                    continue;
                }

                $this->entityManager->clear();
                $this->bus->dispatch($message, [new ReceivedStamp('async')]);
                $handled = true;
            }

            if ($handled === false) {
                $this->entityManager->clear();

                return;
            }
        }

        self::fail('The XP messages kept queueing each other');
    }
}
