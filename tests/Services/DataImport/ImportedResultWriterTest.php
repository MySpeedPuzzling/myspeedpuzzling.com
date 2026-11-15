<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultWriter;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\DataImport\ImportedResult;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultKnownBy;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultRefusal;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultStatus;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What the import's writer does that the add form does not (docs/features/data-import/writer.md): never twice,
 * refusals as outcomes, the moment an imported result is "tracked at". That it writes the same result as the form is
 * ImportWritePathParityTest's.
 */
final class ImportedResultWriterTest extends KernelTestCase
{
    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_05;

    private Connection $database;
    private EntityManagerInterface $entityManager;
    private Player $player;
    private DataImport $import;
    private DataImportSheet $sheet;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->player = self::getContainer()->get(PlayerRepository::class)->getByUserIdCreateIfNotExists('writer|' . Uuid::uuid7()->toString());
        [$this->import, $this->sheet] = $this->importOf($this->player);
        $this->entityManager->flush();
    }

    public function testARowMakesItsResultOnce(): void
    {
        $row = $this->row('key-a');

        $first = $this->write($this->resultOf($row));
        $second = $this->write($this->resultOf($row));

        self::assertSame(ImportedResultStatus::Added, $first->status);
        self::assertSame(ImportedResultStatus::AlreadyAdded, $second->status);
        self::assertSame(ImportedResultKnownBy::Row, $second->knownBy);
        self::assertSame($first->solvingTimeId, $second->solvingTimeId);
        self::assertSame(1, $this->resultsOfThePlayer());
        self::assertSame($first->solvingTimeId, $this->database->fetchOne('SELECT solving_time_id FROM data_import_row WHERE id = :id', ['id' => $row->id->toString()]));
    }

    public function testTheSameDataInAnotherImportIsNotAddedAgain(): void
    {
        $first = $this->write($this->resultOf($this->row('key-a')));

        [$otherImport, $otherSheet] = $this->importOf($this->player);
        $sameData = $this->row('key-a', $otherImport, $otherSheet);

        $second = $this->write($this->resultOf($sameData));

        self::assertSame(ImportedResultStatus::AlreadyAdded, $second->status);
        self::assertSame(ImportedResultKnownBy::SourceKey, $second->knownBy);
        self::assertSame($first->solvingTimeId, $second->solvingTimeId);
        self::assertSame(1, $this->resultsOfThePlayer());
        self::assertNull($this->database->fetchOne('SELECT solving_time_id FROM data_import_row WHERE id = :id', ['id' => $sameData->id->toString()]));
    }

    public function testAnotherPlayersRowWithTheSameKeyDecidesNothing(): void
    {
        $this->write($this->resultOf($this->row('key-a')));

        $other = self::getContainer()->get(PlayerRepository::class)->getByUserIdCreateIfNotExists('writer|other-' . Uuid::uuid7()->toString());
        [$otherImport, $otherSheet] = $this->importOf($other);
        $otherRow = $this->row('key-a', $otherImport, $otherSheet);

        self::assertSame(ImportedResultStatus::Added, $this->write($this->resultOf($otherRow, playerId: $other->id->toString()))->status);
    }

    public function testAResultThatIsGoneMayBeAddedAgain(): void
    {
        $row = $this->row('key-a');
        $first = $this->write($this->resultOf($row));

        // Deleted by the player, or undone - the row still names it
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE id = :id', ['id' => $first->solvingTimeId]);
        $this->entityManager->clear();

        $again = $this->write($this->resultOf($row));

        self::assertSame(ImportedResultStatus::Added, $again->status);
        self::assertNotSame($first->solvingTimeId, $again->solvingTimeId);
        self::assertSame($again->solvingTimeId, $this->database->fetchOne('SELECT solving_time_id FROM data_import_row WHERE id = :id', ['id' => $row->id->toString()]));
    }

    public function testAFirstTrySomebodyHoldsIsARefusalNotAnException(): void
    {
        $this->write($this->resultOf($this->row('key-a'), firstTry: true));

        $outcome = $this->write($this->resultOf($this->row('key-b'), day: '2026-07-01', firstTry: true));

        self::assertSame(ImportedResultStatus::Refused, $outcome->status);
        self::assertSame(ImportedResultRefusal::FirstTryTaken, $outcome->refusal);
        self::assertNotNull($outcome->firstTry);
        self::assertTrue($outcome->firstTry->hasHolds());
        self::assertNull($outcome->solvingTimeId);
        self::assertSame(1, $this->resultsOfThePlayer());

        // Without the tag it is a result like any other
        self::assertSame(ImportedResultStatus::Added, $this->write($this->resultOf($this->row('key-b'), day: '2026-07-01'))->status);
    }

    public function testWhatTheFileDoesNotSayIsNoFirstTry(): void
    {
        $outcome = $this->write($this->resultOf($this->row('key-a'), firstTry: null));

        self::assertFalse($this->database->fetchOne('SELECT first_attempt FROM puzzle_solving_time WHERE id = :id', ['id' => $outcome->solvingTimeId]));
    }

    public function testRefusalsWriteNothing(): void
    {
        // 1000 pieces in five minutes
        self::assertSame(ImportedResultRefusal::TooFast, $this->write($this->resultOf($this->row('key-a'), seconds: 300))->refusal);
        // "With" nobody but oneself
        self::assertSame(ImportedResultRefusal::EmptyGroup, $this->write($this->resultOf($this->row('key-b'), groupPlayers: ['#' . $this->player->code]))->refusal);
        self::assertSame(ImportedResultRefusal::PuzzleNotFound, $this->write($this->resultOf($this->row('key-c'), puzzleId: Uuid::uuid7()->toString()))->refusal);
        self::assertSame(ImportedResultRefusal::CommentTooLong, $this->write($this->resultOf($this->row('key-d'), comment: str_repeat('á', ImportedResultWriter::COMMENT_MAX_LENGTH + 1)))->refusal);

        self::assertSame(0, $this->resultsOfThePlayer());
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM data_import_row WHERE solving_time_id IS NOT NULL AND import_id = :id', ['id' => $this->import->id->toString()]));
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM puzzling_team_member WHERE player_id = :id", ['id' => $this->player->id->toString()]));
    }

    public function testTheLongestCommentTheFormTakesIsTaken(): void
    {
        $outcome = $this->write($this->resultOf($this->row('key-a'), comment: str_repeat('á', ImportedResultWriter::COMMENT_MAX_LENGTH)));

        self::assertSame(ImportedResultStatus::Added, $outcome->status);
    }

    public function testARowOfSomebodyElseIsABug(): void
    {
        $row = $this->row('key-a');

        $this->expectException(LogicException::class);

        $this->write($this->resultOf($row, playerId: PlayerFixture::PLAYER_REGULAR));
    }

    public function testNothingIsToldOrQueuedForAnImportedResult(): void
    {
        $follower = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($follower);
        $follower->addFavoritePlayer($this->player);
        $this->entityManager->flush();

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $outcome = $this->write($this->resultOf($this->row('key-a')));

        self::assertSame([], $transport->getSent());
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM notification WHERE target_solving_time_id = :id', ['id' => $outcome->solvingTimeId]));
        self::assertSame('spreadsheet_import', $this->database->fetchOne('SELECT created_via FROM puzzle_solving_time WHERE id = :id', ['id' => $outcome->solvingTimeId]));
        // What the batch owes for it
        self::assertSame(self::PUZZLE, $outcome->puzzleId);
        self::assertSame([$this->player->id->toString()], $outcome->memberPlayerIds);
        self::assertTrue($outcome->predictionPending);
        self::assertNull($outcome->removedWishListItem);
    }

    public function testTrackedAtIsNoonOfTheSolveDayWhereThePlayerLives(): void
    {
        // Noon of 1 June in Auckland (UTC+12 then) is midnight UTC of the same day
        $auckland = $this->write($this->resultOf($this->row('key-a'), timezone: 'Pacific/Auckland'));
        // Noon in Los Angeles (UTC-7 then) is 19:00 UTC
        $losAngeles = $this->write($this->resultOf($this->row('key-b'), timezone: 'America/Los_Angeles', position: 12));

        self::assertSame('2026-06-01 00:00:00', $this->trackedAt($auckland));
        self::assertSame('2026-06-01 19:00:12', $this->trackedAt($losAngeles));
        self::assertSame('2026-06-01 00:00:00', $this->database->fetchOne('SELECT finished_at FROM puzzle_solving_time WHERE id = :id', ['id' => $losAngeles->solvingTimeId]));
    }

    public function testThePositionKeepsAnOrderAndNeverLeavesTheHourAfterNoon(): void
    {
        $at = static fn (int $position): string => new ImportedResult('row', 'key', 'player', 'puzzle', 60, '2026-06-01', 'UTC', position: $position)->trackedAt()->format('Y-m-d H:i:s');

        self::assertSame('2026-06-01 12:00:00', $at(0));
        self::assertSame('2026-06-01 12:00:01', $at(1));
        self::assertSame('2026-06-01 12:59:59', $at(3599));
        self::assertSame('2026-06-01 12:00:00', $at(3600));
        self::assertSame('2026-06-01 12:53:20', $at(50_000));
    }

    public function testTheZoneIsThePlayersOwnThenTheirCountrysThenUtc(): void
    {
        self::assertSame('UTC', ImportedResult::zoneOf($this->player));

        $this->player->changeCountry(CountryCode::cz);
        self::assertSame('Europe/Prague', ImportedResult::zoneOf($this->player));

        $this->player->changeTimezone('America/Toronto');
        self::assertSame('America/Toronto', ImportedResult::zoneOf($this->player));
    }

    public function testAnImportedResultIsACheckedValue(): void
    {
        foreach (
            [
            ['solvedDay' => '2026-02-30'],
            ['solvedDay' => '2026-06'],
            ['timezone' => 'Mars/Olympus'],
            ['seconds' => 0],
            ['position' => -1],
            ['sourceKey' => ''],
            ] as $wrong
        ) {
            $arguments = $wrong + ['importRowId' => 'row', 'sourceKey' => 'key', 'playerId' => 'player', 'puzzleId' => 'puzzle', 'seconds' => 60, 'solvedDay' => '2026-06-01', 'timezone' => 'UTC'];

            $refused = false;

            try {
                new ImportedResult(...$arguments);
            } catch (InvalidArgumentException) {
                $refused = true;
            }

            self::assertTrue($refused, 'Accepted: ' . json_encode($wrong));
        }
    }

    public function testAResultWithoutATimeIsNeverAFirstTry(): void
    {
        $outcome = $this->write($this->resultOf($this->row('key-a'), seconds: null, firstTry: true));

        self::assertSame(ImportedResultStatus::Added, $outcome->status);
        self::assertFalse($this->database->fetchOne('SELECT first_attempt FROM puzzle_solving_time WHERE id = :id', ['id' => $outcome->solvingTimeId]));
        self::assertFalse($outcome->predictionPending);
    }

    /**
     * @return array{DataImport, DataImportSheet}
     */
    private function importOf(Player $player): array
    {
        $import = DataImport::uploadOfFile(Uuid::uuid7(), $player, 'results.xlsx', DataImportFileKind::Xlsx, 1000, 'imports/' . Uuid::uuid7()->toString(), TestClock::now());
        $sheet = new DataImportSheet(Uuid::uuid7(), $import, 0, 'Results');
        $this->entityManager->persist($import);
        $this->entityManager->persist($sheet);

        return [$import, $sheet];
    }

    private function row(string $sourceKey, null|DataImport $import = null, null|DataImportSheet $sheet = null): DataImportRow
    {
        $row = new DataImportRow(Uuid::uuid7(), $import ?? $this->import, $sheet ?? $this->sheet, 2, 1, 1);
        $row->sourceKey = $sourceKey;
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $row;
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function resultOf(
        DataImportRow $row,
        null|int $seconds = 5400,
        string $day = '2026-06-01',
        null|bool $firstTry = false,
        array $groupPlayers = [],
        null|string $comment = null,
        string $timezone = 'UTC',
        int $position = 0,
        null|string $playerId = null,
        string $puzzleId = self::PUZZLE,
    ): ImportedResult {
        return new ImportedResult(
            importRowId: $row->id->toString(),
            sourceKey: (string) $row->sourceKey,
            playerId: $playerId ?? $this->player->id->toString(),
            puzzleId: $puzzleId,
            seconds: $seconds,
            solvedDay: $day,
            timezone: $timezone,
            firstTry: $firstTry,
            groupPlayers: $groupPlayers,
            comment: $comment,
            position: $position,
        );
    }

    private function write(ImportedResult $result): ImportedResultOutcome
    {
        return $this->entityManager->wrapInTransaction(
            static fn (): ImportedResultOutcome => self::getContainer()->get(ImportedResultWriter::class)->write($result),
        );
    }

    private function resultsOfThePlayer(): int
    {
        return $this->number('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $this->player->id->toString()]);
    }

    private function trackedAt(ImportedResultOutcome $outcome): mixed
    {
        return $this->database->fetchOne('SELECT tracked_at FROM puzzle_solving_time WHERE id = :id', ['id' => $outcome->solvingTimeId]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function number(string $sql, array $parameters): int
    {
        $value = $this->database->fetchOne($sql, $parameters);
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
