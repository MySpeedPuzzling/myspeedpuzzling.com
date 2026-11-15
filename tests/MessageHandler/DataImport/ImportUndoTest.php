<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\ApplyImportRow;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\ChangeImportAddingPaused;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\ProcessImportBatch;
use SpeedPuzzling\Web\Message\ProcessImportUndo;
use SpeedPuzzling\Web\Message\SendImportAddedEmail;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Message\UndoImportRow;
use SpeedPuzzling\Web\MessageHandler\ProcessImportBatchHandler;
use SpeedPuzzling\Web\MessageHandler\ProcessImportUndoHandler;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Undo (docs/features/data-import/adding.md "Undo"): a row, a batch or everything an import added is taken back -
 * exactly what the rows made, and what the player changed since is kept.
 */
final class ImportUndoTest extends KernelTestCase
{
    use ImportAddingScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testUndoOfABatchTakesBackWhatItAddedAndPutsBackWhatItRemoved(): void
    {
        $player = $this->seedPairingPlayer('undo');
        $brand = $this->seedBrand('Zqundo');
        $solved = $this->seedCataloguePuzzle('Zq Undo Solved', 500, $brand);
        $bought = $this->seedCataloguePuzzle('Zq Undo Bought', 500, $brand);
        $wished = $this->seedCataloguePuzzle('Zq Undo Wished', 500, $brand);
        $before = $this->typedResult($player, $solved, '2020-01-01', 9000);
        // On the wish list with "remove when solved / when I own it" - the import honours it, Undo puts them back
        $wishOfSolved = Uuid::uuid7()->toString();
        $wishOfBought = Uuid::uuid7()->toString();
        $this->pairingDatabase()->insert('wish_list_item', ['id' => $wishOfSolved, 'player_id' => $player->id->toString(), 'puzzle_id' => $solved, 'remove_on_collection_add' => 'true', 'added_at' => '2019-05-05 10:00:00']);
        $this->pairingDatabase()->insert('wish_list_item', ['id' => $wishOfBought, 'player_id' => $player->id->toString(), 'puzzle_id' => $bought, 'remove_on_collection_add' => 'true', 'added_at' => '2019-06-06 10:00:00']);
        $statisticsBefore = $this->pairingDatabase()->fetchOne('SELECT solved_times_count FROM puzzle_statistics WHERE puzzle_id = :id', ['id' => $solved]);

        [$import, $sheet] = $this->importOf($player);
        $resultRow = $this->resultRow($import, $sheet, 'Zq Undo Solved', 500, 'Zqundo', '2024-01-01', 8800, ['custom' => ['how_i_liked_it' => 5]]);
        $ownedRow = $this->ownedRow($import, $sheet, 'Zq Undo Bought', 500, 'Zqundo');
        $wishRow = $this->wishRow($import, $sheet, 'Zq Undo Wished', 500, 'Zqundo');
        $this->pair($import);
        $added = $this->addAndWork($import, $player);

        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM wish_list_item WHERE id IN (:a, :b)', ['a' => $wishOfSolved, 'b' => $wishOfBought]));
        $resultId = $this->rowNow($resultRow)['solving_time_id'];
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM solving_time_custom_values WHERE solving_time_id = :id', ['id' => $resultId]));
        self::assertSame(['solved', 'collection'], [$this->removedFromWishListWhy($resultRow), $this->removedFromWishListWhy($ownedRow)]);

        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $async->reset();

        $undo = $this->undo($import, $player, DataImportUndoScope::Batch, $added->id);

        self::assertSame([AddImportRowsOutcome::STARTED, 3], [$undo->status, $undo->rows]);
        // The player's own result is untouched, the imported one is gone - buried and tombstoned like any deleted one
        self::assertSame([$before], array_column($this->resultsOf($player), 'id'));
        self::assertSame(
            [['solving_time_id' => $resultId, 'deleted_via' => 'import_undo']],
            $this->pairingDatabase()->fetchAllAssociative('SELECT solving_time_id, deleted_via FROM solving_time_graveyard WHERE player_id = :id', ['id' => $player->id->toString()]),
        );
        self::assertSame([$resultId], $this->pairingDatabase()->fetchFirstColumn('SELECT solving_time_id FROM solving_time_tombstone WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM solving_time_custom_values WHERE solving_time_id = :id', ['id' => $resultId]));
        self::assertSame($statisticsBefore, $this->pairingDatabase()->fetchOne('SELECT solved_times_count FROM puzzle_statistics WHERE puzzle_id = :id', ['id' => $solved]));
        // XP through the deletion's own path
        self::assertCount(1, array_filter($async->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof CompensateXpForDeletedSolve));

        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM collection_item WHERE player_id = :id', ['id' => $player->id->toString()]));
        // The wish list is what it was: the two items back with their ids and dates, the imported one gone
        self::assertSame(
            [['id' => $wishOfSolved, 'puzzle_id' => $solved, 'added_at' => '2019-05-05 10:00:00', 'remove_on_collection_add' => true], ['id' => $wishOfBought, 'puzzle_id' => $bought, 'added_at' => '2019-06-06 10:00:00', 'remove_on_collection_add' => true]],
            $this->pairingDatabase()->fetchAllAssociative('SELECT id, puzzle_id, added_at, remove_on_collection_add FROM wish_list_item WHERE player_id = :id ORDER BY added_at', ['id' => $player->id->toString()]),
        );
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM wish_list_item WHERE puzzle_id = :id', ['id' => $wished]));

        foreach ([$resultRow, $ownedRow, $wishRow] as $rowId) {
            $row = $this->rowNow($rowId);
            self::assertSame(['waiting', null, false, null, null, null, null], [$row['state'], $row['failure'], $row['result_made'], $row['collection_item_id'], $row['wish_list_item_id'], $row['made'], $row['undo_id']]);
        }

        $undoNow = $this->undoNow($undo->id);
        self::assertSame(
            ['finished', 'batch', $added->id, 3, 1, 0, 1, 1, 2, false, $player->id->toString()],
            [$undoNow['state'], $undoNow['scope'], $undoNow['batch_id'], $undoNow['rows_undone'], $undoNow['results_deleted'], $undoNow['results_kept_edited'], $undoNow['collection_items_removed'], $undoNow['wish_list_items_removed'], $undoNow['wish_list_items_restored'], $undoNow['by_admin'], $undoNow['requested_by_player_id']],
        );
        self::assertNotNull($this->batchNow($added->id)['undone_at']);

        // The rows can be added again - a new result, a new id (no tombstone names a living result)
        $again = $this->addAndWork($import, $player);
        self::assertSame(3, $this->batchNow($again->id)['rows_added']);
        $newResultId = $this->rowNow($resultRow)['solving_time_id'];
        self::assertNotSame($resultId, $newResultId);
        self::assertCount(2, $this->resultsOf($player));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM solving_time_tombstone WHERE solving_time_id = :id', ['id' => $newResultId]));
    }

    public function testAResultChangedSinceTheImportIsKeptByABatchUndoAndTheFactIsToldForARow(): void
    {
        $player = $this->seedPairingPlayer('edited');
        $brand = $this->seedBrand('Zqedited');
        $this->seedCataloguePuzzle('Zq Edited One', 500, $brand);
        $this->seedCataloguePuzzle('Zq Edited Two', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $edited = $this->resultRow($import, $sheet, 'Zq Edited One', 500, 'Zqedited', '2024-01-01', 1800, kind: DataImportRowKind::Both);
        $untouched = $this->resultRow($import, $sheet, 'Zq Edited Two', 500, 'Zqedited', '2024-01-02', 1900);
        $this->pair($import);
        $added = $this->addAndWork($import, $player);
        $editedResult = $this->rowNow($edited)['solving_time_id'];
        // The player corrected the time on their profile
        $this->pairingDatabase()->update('puzzle_solving_time', ['seconds_to_solve' => 1750], ['id' => $editedResult]);

        $undo = $this->undo($import, $player, DataImportUndoScope::Batch, $added->id);

        $undoNow = $this->undoNow($undo->id);
        self::assertSame([2, 1, 1, 1, 0], [$undoNow['rows_requested'], $undoNow['rows_undone'], $undoNow['results_deleted'], $undoNow['results_kept_edited'], $undoNow['collection_items_removed']]);
        // Kept whole: the result, the row's collection puzzle, the row itself
        self::assertSame([$editedResult], array_column($this->resultsOf($player), 'id'));
        $row = $this->rowNow($edited);
        self::assertSame(['added', true, null, $undo->id], [$row['state'], $row['collection_item_id'] !== null, $row['undo_id'], $row['made']['keptByUndo'] ?? null]);
        self::assertSame('waiting', $this->rowNow($untouched)['state']);

        // An Undo of the whole import keeps it too
        $whole = $this->undo($import, $player, DataImportUndoScope::Import);
        self::assertSame([1, 0, 1], [$this->undoNow($whole->id)['rows_requested'], $this->undoNow($whole->id)['rows_undone'], $this->undoNow($whole->id)['results_kept_edited']]);

        // The row alone: kept as well and said so - until the player says "this one too"
        $single = $this->undo($import, $player, DataImportUndoScope::Rows, rowIds: [$edited]);
        self::assertSame([0, 1], [$this->undoNow($single->id)['rows_undone'], $this->undoNow($single->id)['results_kept_edited']]);
        self::assertCount(1, $this->resultsOf($player));

        $forced = $this->undo($import, $player, DataImportUndoScope::Rows, rowIds: [$edited], includeEdited: true);
        $forcedNow = $this->undoNow($forced->id);
        self::assertSame([1, 1, 1, 0, 1], [$forcedNow['rows_undone'], $forcedNow['results_deleted'], $forcedNow['results_deleted_edited'], $forcedNow['results_kept_edited'], $forcedNow['collection_items_removed']]);
        self::assertSame([], $this->resultsOf($player));
        self::assertSame('waiting', $this->rowNow($edited)['state']);
    }

    public function testUndoOfOneRowAndOfTheWholeImport(): void
    {
        $player = $this->seedPairingPlayer('scopes');
        $brand = $this->seedBrand('Zqscopes');

        foreach (['One', 'Two', 'Three'] as $name) {
            $this->seedCataloguePuzzle('Zq Scopes ' . $name, 500, $brand);
        }

        [$import, $sheet] = $this->importOf($player);
        $one = $this->resultRow($import, $sheet, 'Zq Scopes One', 500, 'Zqscopes', '2024-01-01', 1800);
        $two = $this->resultRow($import, $sheet, 'Zq Scopes Two', 500, 'Zqscopes', '2024-01-02', 1900);
        $three = $this->resultRow($import, $sheet, 'Zq Scopes Three', 500, 'Zqscopes', '2024-01-03', 2000);
        $this->pair($import);
        // Two batches
        $this->addAndWork($import, $player, [$one, $two]);
        $this->addAndWork($import, $player, [$three]);

        // One row
        $requestId = Uuid::uuid7()->toString();
        $single = $this->undo($import, $player, DataImportUndoScope::Rows, rowIds: [$two], requestId: $requestId);
        self::assertSame(['waiting', 'added', 'added'], [$this->rowNow($two)['state'], $this->rowNow($one)['state'], $this->rowNow($three)['state']]);
        self::assertCount(2, $this->resultsOf($player));
        // The same request again answers the same Undo
        $resent = $this->undo($import, $player, DataImportUndoScope::Rows, rowIds: [$two], requestId: $requestId);
        self::assertSame([AddImportRowsOutcome::SAME_REQUEST, $single->id], [$resent->status, $resent->id]);
        // A row that made nothing is nothing to take back
        self::assertSame(AddImportRowsOutcome::NOTHING, $this->undo($import, $player, DataImportUndoScope::Rows, rowIds: [$two])->status);

        // Everything the import added, across its batches
        $whole = $this->undo($import, $player, DataImportUndoScope::Import);
        self::assertSame(2, $this->undoNow($whole->id)['rows_undone']);
        self::assertSame([], $this->resultsOf($player));
        self::assertSame(['waiting', 'waiting', 'waiting'], [$this->rowNow($one)['state'], $this->rowNow($two)['state'], $this->rowNow($three)['state']]);
    }

    public function testOnlyTheOwnerUndoesAndAnAdminUndoesAnybodysBatchWithAReason(): void
    {
        $player = $this->seedPairingPlayer('owner');
        $stranger = $this->seedPairingPlayer('stranger');
        $this->seedCataloguePuzzle('Zq Owner Undo', 500, $this->seedBrand('Zqownerundo'));

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Owner Undo', 500, 'Zqownerundo', '2024-01-01', 1800);
        $this->pair($import);
        $added = $this->addAndWork($import, $player);

        try {
            $this->undo($import, $stranger, DataImportUndoScope::Import);
            self::fail('Somebody else\'s import is "not found".');
        } catch (DataImportNotFound) {
            // An http exception arrives as it is (UnwrapHttpExceptionMiddleware)
        }

        try {
            $this->undo($import, PlayerFixture::PLAYER_ADMIN, DataImportUndoScope::Batch, $added->id, asAdmin: true, reason: '  ');
            self::fail('An admin gives a reason.');
        } catch (HandlerFailedException $refused) {
            self::assertInstanceOf(\InvalidArgumentException::class, $refused->getPrevious());
        }

        self::assertSame('added', $this->rowNow($row)['state']);

        $undo = $this->undo($import, PlayerFixture::PLAYER_ADMIN, DataImportUndoScope::Batch, $added->id, asAdmin: true, reason: 'Results landed on the wrong puzzle');

        $undoNow = $this->undoNow($undo->id);
        self::assertSame(['finished', true, PlayerFixture::PLAYER_ADMIN, 'Results landed on the wrong puzzle', 1], [$undoNow['state'], $undoNow['by_admin'], $undoNow['requested_by_player_id'], $undoNow['reason'], $undoNow['results_deleted']]);
        self::assertSame([], $this->resultsOf($player));
        self::assertSame('waiting', $this->rowNow($row)['state']);
    }

    public function testABatchThatStillRunsIsStoppedFirstEvenWhileAddingIsPaused(): void
    {
        $player = $this->seedPairingPlayer('running');
        $brand = $this->seedBrand('Zqrunning');
        $this->seedCataloguePuzzle('Zq Running One', 500, $brand);
        $this->seedCataloguePuzzle('Zq Running Two', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $one = $this->resultRow($import, $sheet, 'Zq Running One', 500, 'Zqrunning', '2024-01-01', 1800);
        $two = $this->resultRow($import, $sheet, 'Zq Running Two', 500, 'Zqrunning', '2024-01-02', 1900);
        $this->pair($import);

        // One row is added, then the emergency: adding paused, the batch taken back
        $added = $this->add($import, $player);
        self::assertNotNull($added->id);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new ApplyImportRow($one, $added->id, $player->id->toString()));
        $bus->dispatch(new ChangeImportAddingPaused(true, PlayerFixture::PLAYER_ADMIN, 'Emergency'));
        self::assertCount(1, $this->resultsOf($player));

        $undo = $this->undo($import, PlayerFixture::PLAYER_ADMIN, DataImportUndoScope::Batch, $added->id, asAdmin: true, reason: 'Emergency');

        self::assertSame(['finished', 1], [$this->undoNow($undo->id)['state'], $this->undoNow($undo->id)['results_deleted']]);
        self::assertSame([], $this->resultsOf($player));
        self::assertSame(['waiting', 'waiting'], [$this->rowNow($one)['state'], $this->rowNow($two)['state']]);

        // The stopped batch ends with its next turn - the pause does not hold it, it has nothing left to add - and
        // tells the player nothing: "1 added" just before it is taken back would be noise. The admin's Undo tells
        // them once, with the reason
        self::assertSame(['running', true], [$this->batchNow($added->id)['state'], $this->batchNow($added->id)['ended_by_undo']]);
        $this->work($added->id);
        self::assertSame(['finished', null], [$this->batchNow($added->id)['state'], $this->batchNow($added->id)['notified_at']]);
        self::assertSame(
            ['ImportRowsTakenBack'],
            $this->pairingDatabase()->fetchFirstColumn('SELECT type FROM notification WHERE player_id = :id', ['id' => $player->id->toString()]),
        );
        $notifications = self::getContainer()->get(GetNotifications::class)->forPlayer($player->id->toString(), 10, 0);
        self::assertCount(1, $notifications);
        self::assertTrue($notifications[0]->isImportNotification());
        self::assertSame([1, 'Emergency', $import->id->toString()], [$notifications[0]->importResultsTakenBack, $notifications[0]->importTakenBackReason, $notifications[0]->importId]);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        self::assertSame([], array_filter($async->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof SendImportAddedEmail));

        // A job of the stopped batch that still waited finds nothing to do
        self::getContainer()->get(ProcessImportBatchHandler::class)(new ProcessImportBatch($added->id, 1));
        self::assertSame([], $this->resultsOf($player));
    }

    public function testAnUndoOfTheImportOrOfRowsStopsABatchThatStillRuns(): void
    {
        $player = $this->seedPairingPlayer('half-way');
        $brand = $this->seedBrand('Zqhalf');

        foreach (['One', 'Two', 'Three', 'Four'] as $name) {
            $this->seedCataloguePuzzle('Zq Half ' . $name, 500, $brand);
        }

        [$import, $sheet] = $this->importOf($player);
        $rows = [];

        foreach (['One', 'Two', 'Three', 'Four'] as $index => $name) {
            $rows[] = $this->resultRow($import, $sheet, 'Zq Half ' . $name, 500, 'Zqhalf', '2024-01-0' . ($index + 1), 3000 + $index);
        }

        $this->pair($import);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        // Two of four rows are added when the player takes ONE ROW back
        $added = $this->add($import, $player);
        self::assertNotNull($added->id);
        $bus->dispatch(new ApplyImportRow($rows[0], $added->id, $player->id->toString()));
        $bus->dispatch(new ApplyImportRow($rows[1], $added->id, $player->id->toString()));

        $this->undo($import, $player, DataImportUndoScope::Rows, rowIds: [$rows[0]]);

        // The batch is stopped: what waited for its turn waits again, nothing more is added behind the Undo's back
        self::assertSame(['waiting', 'added', 'waiting', 'waiting'], array_map(fn (string $rowId): string => $this->rowNow($rowId)['state'], $rows));
        $this->work($added->id);
        self::assertSame(['finished', true, 1], [$this->batchNow($added->id)['state'], $this->batchNow($added->id)['ended_by_undo'], $this->batchNow($added->id)['rows_added']]);
        self::assertCount(1, $this->resultsOf($player));
        // The player's own Undo tells nothing, and the stopped batch neither
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $player->id->toString()]));
        // What the stopped batch did add is followed up all the same
        $imports = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $imports);
        self::assertNotEmpty(array_filter($imports->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof AwardXpForSolvingTime));

        // The same for everything the import added: a new batch half-way, then Undo of the import
        $again = $this->add($import, $player);
        self::assertNotNull($again->id);
        $bus->dispatch(new ApplyImportRow($rows[0], $again->id, $player->id->toString()));

        $whole = $this->undo($import, $player, DataImportUndoScope::Import);

        self::assertSame(2, $this->undoNow($whole->id)['results_deleted']);
        self::assertSame(['waiting', 'waiting', 'waiting', 'waiting'], array_map(fn (string $rowId): string => $this->rowNow($rowId)['state'], $rows));
        $this->work($again->id);
        self::assertSame('finished', $this->batchNow($again->id)['state']);
        self::assertSame([], $this->resultsOf($player));
    }

    public function testAPuzzleMergedSinceTheImportIsNotThePlayersChange(): void
    {
        $player = $this->seedPairingPlayer('merged-undo');
        $brand = $this->seedBrand('Zqmergedundo');
        $survivor = $this->seedCataloguePuzzle('Zq Undo Merge Survivor', 500, $brand);
        $duplicate = $this->seedCataloguePuzzle('Zq Undo Merge Duplicate', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Undo Merge Duplicate', 500, 'Zqmergedundo', '2024-02-02', 4000);
        $this->pair($import);
        $added = $this->addAndWork($import, $player);
        self::assertSame([$duplicate], array_column($this->resultsOf($player), 'puzzle_id'));

        // The catalogue merges the puzzle: the result moves to the survivor - nobody edited it
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $mergeRequestId = Uuid::uuid7()->toString();
        $bus->dispatch(new SubmitPuzzleMergeRequest(mergeRequestId: $mergeRequestId, sourcePuzzleId: $survivor, reporterId: PlayerFixture::PLAYER_REGULAR, duplicatePuzzleIds: [$duplicate]));
        $bus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: $survivor,
            mergedName: 'Zq Undo Merge Survivor',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: $brand->id->toString(),
            selectedImagePuzzleId: null,
        ));
        self::assertSame([$survivor], array_column($this->resultsOf($player), 'puzzle_id'));

        $undo = $this->undo($import, $player, DataImportUndoScope::Batch, $added->id);

        self::assertSame([1, 1, 0], [$this->undoNow($undo->id)['rows_undone'], $this->undoNow($undo->id)['results_deleted'], $this->undoNow($undo->id)['results_kept_edited']]);
        self::assertSame([], $this->resultsOf($player));
        self::assertSame('waiting', $this->rowNow($row)['state']);
    }

    public function testARowOfAnUndoDeliveredTwiceIsCountedOnce(): void
    {
        $player = $this->seedPairingPlayer('twice-undone');
        $this->seedCataloguePuzzle('Zq Twice Undone', 500, $this->seedBrand('Zqtwiceundone'));

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Twice Undone', 500, 'Zqtwiceundone', '2024-01-01', 4000);
        $this->pair($import);
        $this->addAndWork($import, $player);

        $undo = $this->undo($import, $player, DataImportUndoScope::Import, work: false);
        self::assertNotNull($undo->id);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new UndoImportRow($undo->id, $row));
        $bus->dispatch(new UndoImportRow($undo->id, $row));
        // A job of an older turn is void, the current one ends the Undo
        $this->pairingDatabase()->update('data_import_undo', ['turn' => 2], ['id' => $undo->id]);
        self::getContainer()->get(ProcessImportUndoHandler::class)(new ProcessImportUndo($undo->id, 1));
        self::assertSame('running', $this->undoNow($undo->id)['state']);
        $this->workUndo($undo->id);

        self::assertSame(['finished', 1, 1], [$this->undoNow($undo->id)['state'], $this->undoNow($undo->id)['rows_undone'], $this->undoNow($undo->id)['results_deleted']]);
    }
}
