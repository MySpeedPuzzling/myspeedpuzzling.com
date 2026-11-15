<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PrivateProfileViewer;
use SpeedPuzzling\Web\Entity\UserBlock;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultWriter;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateClassifier;
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
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
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
        // Noon in Los Angeles (UTC-7 then) is 19:00 UTC; the twelfth row stands 12 steps of 11 seconds later
        $losAngeles = $this->write($this->resultOf($this->row('key-b'), timezone: 'America/Los_Angeles', position: 12, puzzleId: PuzzleFixture::PUZZLE_1000_04));

        self::assertSame('2026-06-01 00:00:00', $this->trackedAt($auckland));
        self::assertSame('2026-06-01 19:02:12', $this->trackedAt($losAngeles));
        self::assertSame('2026-06-01 00:00:00', $this->database->fetchOne('SELECT finished_at FROM puzzle_solving_time WHERE id = :id', ['id' => $losAngeles->solvingTimeId]));
    }

    public function testThePositionKeepsAnOrderInStepsLongerThanTenSecondsInsideTheHourAfterNoon(): void
    {
        $at = static fn (int $position): string => new ImportedResult('row', 'key', 'player', 'puzzle', 60, '2026-06-01', 'UTC', position: $position)->trackedAt(TestClock::now())->format('Y-m-d H:i:s');

        self::assertSame('2026-06-01 12:00:00', $at(0));
        self::assertSame('2026-06-01 12:00:11', $at(1));
        self::assertSame('2026-06-01 12:59:46', $at(ImportedResult::STEPS - 1));
        self::assertSame('2026-06-01 12:00:00', $at(ImportedResult::STEPS));
        self::assertGreaterThan(DuplicateClassifier::CERTAIN_MAX_GAP_SECONDS, ImportedResult::STEP_SECONDS);
    }

    /**
     * Now is 14 October 2026, noon UTC: 05:00 in Los Angeles, and already 01:00 of the 15th in Auckland.
     */
    public function testTrackedAtIsNeverInTheFuture(): void
    {
        $at = static fn (string $day, string $zone, int $position = 0): string => new ImportedResult('row', 'key', 'player', 'puzzle', 60, $day, $zone, position: $position)->trackedAt(TestClock::now())->format('Y-m-d H:i:s');

        // West: noon of today is seven hours ahead
        self::assertSame('2026-10-14 11:59:59', $at('2026-10-14', 'America/Los_Angeles'));
        // East: their today is our tomorrow, its noon eleven hours ahead
        self::assertSame('2026-10-14 11:59:59', $at('2026-10-15', 'Pacific/Auckland'));
        // Here: noon is now, the row's place would be after it
        self::assertSame('2026-10-14 11:59:59', $at('2026-10-14', 'UTC', position: 5));
        // What is over stays where it is
        self::assertSame('2026-10-13 19:00:00', $at('2026-10-13', 'America/Los_Angeles'));
        self::assertSame('2026-10-13 23:00:00', $at('2026-10-14', 'Pacific/Auckland'));
    }

    public function testResultsOfTodayAreNotInTheFutureAndNotAtOneMoment(): void
    {
        $first = $this->write($this->resultOf($this->row('key-a'), day: '2026-10-14', timezone: 'America/Los_Angeles'));
        $second = $this->write($this->resultOf($this->row('key-b'), seconds: 6000, day: '2026-10-14', timezone: 'America/Los_Angeles', position: 1));

        self::assertSame('2026-10-14 11:59:59', $this->trackedAt($first));
        self::assertSame('2026-10-14 11:59:48', $this->trackedAt($second));
    }

    /**
     * Two equal rows of a file, both real (the player said "two"): next to each other, ten rows apart, a whole turn of
     * the steps apart - never at one moment, never within the ten seconds of "a form sent twice".
     */
    public function testEqualRowsNeverStandWithinTenSecondsOfEachOtherAndTheDailyJobRemovesNeither(): void
    {
        $outcomes = [];

        foreach ([0, 0, 1, 10, ImportedResult::STEPS, ImportedResult::STEPS * 2 + 1] as $index => $position) {
            $outcomes[] = $this->write($this->resultOf($this->row('key-' . $index), position: $position));
        }

        $moments = array_map(function (ImportedResultOutcome $outcome): int {
            $trackedAt = $this->trackedAt($outcome);
            self::assertIsString($trackedAt);

            return new DateTimeImmutable($trackedAt)->getTimestamp();
        }, $outcomes);
        sort($moments);

        for ($i = 1; $i < count($moments); $i++) {
            self::assertGreaterThan(DuplicateClassifier::CERTAIN_MAX_GAP_SECONDS, $moments[$i] - $moments[$i - 1]);
        }

        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);
        $this->entityManager->clear();

        self::assertSame(6, $this->resultsOfThePlayer());
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM result_auto_removal WHERE player_id = :id', ['id' => $this->player->id->toString()]));
    }

    /**
     * Even at one moment (two results an older version wrote, or anything else): a pair with an imported result in it
     * is never the certain tier - the player decides.
     */
    public function testTheDailyJobNeverRemovesAnImportedResultByItself(): void
    {
        $first = $this->write($this->resultOf($this->row('key-a')));
        $second = $this->write($this->resultOf($this->row('key-b'), position: 1));

        $this->database->executeStatement('UPDATE puzzle_solving_time SET tracked_at = :at WHERE id IN (:a, :b)', ['at' => '2026-06-01 12:00:00', 'a' => $first->solvingTimeId, 'b' => $second->solvingTimeId]);
        $this->entityManager->clear();

        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);
        $this->entityManager->clear();

        self::assertSame(2, $this->resultsOfThePlayer());
        $tier = $this->database->fetchOne('SELECT tier FROM result_duplicate_case WHERE player_id = :id', ['id' => $this->player->id->toString()]);
        self::assertIsString($tier, 'The pair is a case the player decides.');
        self::assertNotSame('certain', $tier);
    }

    public function testTheWriterRefusesToWriteOutsideATransaction(): void
    {
        $result = $this->resultOf($this->row('key-a'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a transaction');

        self::getContainer()->get(ImportedResultWriter::class)->write($result);
    }

    /**
     * The caller loaded the row before another delivery linked it: the loaded row says "no result yet", the database
     * says otherwise - the database is asked.
     */
    public function testALoadedRowThatIsOlderThanTheDatabaseDoesNotMakeASecondResult(): void
    {
        $delivered = $this->write($this->resultOf($this->row('key-first-delivery')));

        $row = $this->row('key-a');
        self::assertNull($row->solvingTimeId);

        // What the first delivery's transaction did, behind the loaded row's back
        $this->database->executeStatement('UPDATE data_import_row SET solving_time_id = NULL WHERE solving_time_id = :time', ['time' => $delivered->solvingTimeId]);
        $this->database->executeStatement('UPDATE data_import_row SET solving_time_id = :time WHERE id = :id', ['time' => $delivered->solvingTimeId, 'id' => $row->id->toString()]);
        self::assertNull($row->solvingTimeId);

        $second = $this->write($this->resultOf($row));

        self::assertSame(ImportedResultStatus::AlreadyAdded, $second->status);
        self::assertSame(ImportedResultKnownBy::Row, $second->knownBy);
        self::assertSame($delivered->solvingTimeId, $second->solvingTimeId);
        self::assertSame(1, $this->resultsOfThePlayer());
    }

    public function testTheLinkIsWrittenOnlyIfTheRowStillNamesWhatWasRead(): void
    {
        $rows = self::getContainer()->get(DataImportRowRepository::class);
        $row = $this->row('key-a')->id->toString();
        $first = Uuid::uuid7()->toString();
        $second = Uuid::uuid7()->toString();

        self::assertNull($rows->linkedSolvingTimeId($row));
        self::assertTrue($rows->linkSolvingTime($row, null, $first));
        // Somebody was faster: the reader that saw "nothing" writes nothing
        self::assertFalse($rows->linkSolvingTime($row, null, $second));
        self::assertSame($first, $rows->linkedSolvingTimeId($row));
        // A link to a result that is gone is replaced by whoever read exactly it
        self::assertTrue($rows->linkSolvingTime($row, $first, $second));
        self::assertSame($second, $rows->linkedSolvingTimeId($row));
    }

    public function testTwoRowsCanNeverNameTheSameResult(): void
    {
        $outcome = $this->write($this->resultOf($this->row('key-a')));
        $other = $this->row('key-b');

        $this->database->beginTransaction();

        try {
            $this->database->executeStatement('UPDATE data_import_row SET solving_time_id = :time WHERE id = :id', ['time' => $outcome->solvingTimeId, 'id' => $other->id->toString()]);
            $refused = false;
        } catch (UniqueConstraintViolationException) {
            $refused = true;
        } finally {
            $this->database->rollBack();
        }

        self::assertTrue($refused);
    }

    public function testTheSameRowAndTheSameKeyTwiceInOneTransactionMakeOneResult(): void
    {
        $row = $this->row('key-a');
        [$otherImport, $otherSheet] = $this->importOf($this->player);
        $sameData = $this->row('key-a', $otherImport, $otherSheet);
        $writer = self::getContainer()->get(ImportedResultWriter::class);

        // Nothing is flushed between the three
        $outcomes = $this->entityManager->wrapInTransaction(fn (): array => [
            $writer->write($this->resultOf($row)),
            $writer->write($this->resultOf($row)),
            $writer->write($this->resultOf($sameData)),
        ]);

        self::assertSame(ImportedResultStatus::Added, $outcomes[0]->status);
        self::assertSame(ImportedResultKnownBy::Row, $outcomes[1]->knownBy);
        self::assertSame(ImportedResultKnownBy::SourceKey, $outcomes[2]->knownBy);
        self::assertSame($outcomes[0]->solvingTimeId, $outcomes[2]->solvingTimeId);
        self::assertSame(1, $this->resultsOfThePlayer());
    }

    public function testATimeLongerThanTheFormTakesIsRefused(): void
    {
        self::assertSame(ImportedResultRefusal::TimeTooLong, $this->write($this->resultOf($this->row('key-a'), seconds: ImportedResult::MAX_SECONDS + 1))->refusal);
        self::assertSame(ImportedResultStatus::Added, $this->write($this->resultOf($this->row('key-a'), seconds: ImportedResult::MAX_SECONDS))->status);
    }

    /**
     * Nobody is signed in where an import is written (a worker). The first-try rules are asked as the importing
     * player: what that player may not see is left out - no refusal, no name - and what they may see counts.
     */
    public function testAFirstTryOfAPublicPartnerRefusesTheRowAndNamesThem(): void
    {
        $partner = $this->partnerHoldingAFirstTry(private: false);

        $outcome = $this->write($this->resultOf($this->row('key-a'), firstTry: true, groupPlayers: ['#' . $partner->code]));

        self::assertSame(ImportedResultRefusal::FirstTryTaken, $outcome->refusal);
        self::assertSame(['Paula Partner'], $this->namesIn($outcome));
    }

    public function testAPartnerThePlayerBlocksIsLeftOutOfTheFirstTryRules(): void
    {
        $partner = $this->partnerHoldingAFirstTry(private: false);
        $this->entityManager->persist(new UserBlock(Uuid::uuid7(), $this->player, $partner, TestClock::now()));
        $this->entityManager->flush();

        $outcome = $this->write($this->resultOf($this->row('key-a'), firstTry: true, groupPlayers: ['#' . $partner->code]));

        self::assertSame(ImportedResultStatus::Added, $outcome->status);
        self::assertNull($outcome->firstTry);
    }

    public function testAPrivatePartnerIsLeftOutOfTheFirstTryRules(): void
    {
        $partner = $this->partnerHoldingAFirstTry(private: true);

        $outcome = $this->write($this->resultOf($this->row('key-a'), firstTry: true, groupPlayers: ['#' . $partner->code]));

        self::assertSame(ImportedResultStatus::Added, $outcome->status);
        self::assertNull($outcome->firstTry);
    }

    public function testAPrivatePartnerWhoLetsThePlayerSeeThemCounts(): void
    {
        $partner = $this->partnerHoldingAFirstTry(private: true);
        $this->entityManager->persist(new PrivateProfileViewer(Uuid::uuid7(), $partner, $this->player, TestClock::now()));
        $this->entityManager->flush();

        $outcome = $this->write($this->resultOf($this->row('key-a'), firstTry: true, groupPlayers: ['#' . $partner->code]));

        self::assertSame(ImportedResultRefusal::FirstTryTaken, $outcome->refusal);
        self::assertSame(['Paula Partner'], $this->namesIn($outcome));
    }

    public function testAnEntryIsOnePersonWhateverItsCommas(): void
    {
        $outcome = $this->write($this->resultOf($this->row('key-a'), groupPlayers: ['Smith, John', ' Anna ', '', 'Anna']));

        self::assertSame(3, $this->database->fetchOne('SELECT puzzlers_count FROM puzzle_solving_time WHERE id = :id', ['id' => $outcome->solvingTimeId]));
        self::assertSame(['Anna', 'Smith, John'], $this->database->fetchFirstColumn(
            'SELECT member.guest_name FROM puzzling_team_member member INNER JOIN puzzle_solving_time time ON time.puzzling_team_id = member.team_id WHERE time.id = :id AND member.guest_name IS NOT NULL ORDER BY 1',
            ['id' => $outcome->solvingTimeId],
        ));
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

    private function partnerHoldingAFirstTry(bool $private): Player
    {
        $userId = 'writer|partner-' . Uuid::uuid7()->toString();
        $partner = self::getContainer()->get(PlayerRepository::class)->getByUserIdCreateIfNotExists($userId);
        $this->entityManager->flush();
        $this->database->executeStatement('UPDATE player SET name = :name, is_private = :private WHERE id = :id', ['name' => 'Paula Partner', 'private' => $private ? 'true' : 'false', 'id' => $partner->id->toString()]);
        $this->entityManager->refresh($partner);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: $userId,
            puzzleId: self::PUZZLE,
            competitionId: null,
            time: '02:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2026-05-01 00:00:00'),
            firstAttempt: true,
            unboxed: false,
        ));

        return $partner;
    }

    /**
     * @return list<string> every name the refusal's assessment would show the player
     */
    private function namesIn(ImportedResultOutcome $outcome): array
    {
        self::assertNotNull($outcome->firstTry);
        $names = [];

        foreach ($outcome->firstTry->holds as $hold) {
            foreach ($hold->people as $person) {
                if ($person->name !== null) {
                    $names[] = $person->name;
                }
            }
        }

        return $names;
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
