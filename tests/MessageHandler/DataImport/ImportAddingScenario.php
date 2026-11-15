<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AddImportRows;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ProcessImportBatch;
use SpeedPuzzling\Web\Message\ProcessImportUndo;
use SpeedPuzzling\Web\Message\UndoImportRows;
use SpeedPuzzling\Web\MessageHandler\ProcessImportBatchHandler;
use SpeedPuzzling\Web\MessageHandler\ProcessImportUndoHandler;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportPairingScenario;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImport\ImportSourceKey;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Imports whose rows are ready to be added, built the way the stages before build them: rows as ImportRowParser
 * leaves them (with their real keys - the same data in two files has the same key), their puzzles built and paired
 * by the real handlers (BuildImportPuzzles; name + pieces + brand on one catalogue puzzle is decided by the rules).
 * Invented puzzles only. "Add" goes through the bus; the worker's turns are taken by hand.
 */
trait ImportAddingScenario
{
    use ImportPairingScenario;

    /**
     * A row with a result, keyed as the parser keys it.
     *
     * @param array<string, mixed> $more type, people, firstTry, comment, custom, notes, alreadyOnMsp, occurrence
     */
    private function resultRow(
        DataImport $import,
        DataImportSheet $sheet,
        string $name,
        int $pieces,
        string $brand,
        string $day,
        null|int $seconds,
        array $more = [],
        DataImportRowKind $kind = DataImportRowKind::Result,
    ): string {
        [$import, $sheet] = $this->managed($import, $sheet);
        $type = is_string($more['type'] ?? null) ? $more['type'] : 'solo';
        $occurrence = is_int($more['occurrence'] ?? null) ? $more['occurrence'] : 1;
        $puzzleKey = ImportPuzzleKey::of($name, $pieces, $brand);
        self::assertNotNull($puzzleKey);
        $key = ImportSourceKey::result($puzzleKey, new DateTimeImmutable($day), DatePrecision::Day, $seconds, PuzzlingType::from($type), $occurrence);

        return $this->seedRow($import, $sheet, $name, $pieces, $brand, [
            'date' => $day,
            'datePrecision' => 'day',
            'seconds' => $seconds,
            'type' => $type,
            'keys' => ['result' => $key],
            ...$more,
        ], $kind, $key);
    }

    /**
     * @param array<string, mixed> $more
     */
    private function ownedRow(DataImport $import, DataImportSheet $sheet, string $name, int $pieces, string $brand, array $more = []): string
    {
        [$import, $sheet] = $this->managed($import, $sheet);
        $puzzleKey = ImportPuzzleKey::of($name, $pieces, $brand);
        self::assertNotNull($puzzleKey);

        return $this->seedRow($import, $sheet, $name, $pieces, $brand, $more, DataImportRowKind::Owned, ImportSourceKey::owned($puzzleKey));
    }

    private function wishRow(DataImport $import, DataImportSheet $sheet, string $name, int $pieces, string $brand): string
    {
        [$import, $sheet] = $this->managed($import, $sheet);
        $puzzleKey = ImportPuzzleKey::of($name, $pieces, $brand);
        self::assertNotNull($puzzleKey);

        return $this->seedRow($import, $sheet, $name, $pieces, $brand, [], DataImportRowKind::WishList, ImportSourceKey::wishList($puzzleKey));
    }

    /**
     * An import of the player with one sheet - the player as the entity manager has them now (a test's own object
     * is detached by every step that clears it).
     *
     * @return array{DataImport, DataImportSheet}
     */
    private function importOf(Player|string $player): array
    {
        $managed = $this->pairingEntityManager()->find(Player::class, is_string($player) ? $player : $player->id->toString());
        self::assertNotNull($managed);

        return $this->seedImport($managed);
    }

    /**
     * @return array{DataImport, DataImportSheet}
     */
    private function managed(DataImport $import, DataImportSheet $sheet): array
    {
        $managedImport = $this->pairingEntityManager()->find(DataImport::class, $import->id->toString());
        $managedSheet = $this->pairingEntityManager()->find(DataImportSheet::class, $sheet->id->toString());
        self::assertNotNull($managedImport);
        self::assertNotNull($managedSheet);

        return [$managedImport, $managedSheet];
    }

    /**
     * "Add" as a page sends it.
     *
     * @param null|list<string> $rowIds
     * @param null|list<string> $importPuzzleIds
     */
    private function add(DataImport|string $import, Player|string $player, null|array $rowIds = null, null|array $importPuzzleIds = null, null|string $requestId = null): AddImportRowsOutcome
    {
        $this->pairingEntityManager()->clear();

        $outcome = self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddImportRows(
            $requestId ?? Uuid::uuid7()->toString(),
            is_string($import) ? $import : $import->id->toString(),
            is_string($player) ? $player : $player->id->toString(),
            $rowIds,
            $importPuzzleIds,
        ))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(AddImportRowsOutcome::class, $outcome);
        $this->pairingEntityManager()->clear();

        return $outcome;
    }

    /**
     * The `imports` worker: turns of the batch until it is finished (or the turns are used up - a paused batch).
     */
    private function work(null|string $batchId, int $turns = 100, null|ProcessImportBatchHandler $handler = null): void
    {
        self::assertNotNull($batchId);
        $handler ??= self::getContainer()->get(ProcessImportBatchHandler::class);

        for ($turn = 0; $turn < $turns; $turn++) {
            if ($this->pairingDatabase()->fetchOne('SELECT state FROM data_import_batch WHERE id = :id', ['id' => $batchId]) !== 'running') {
                break;
            }

            $handler(new ProcessImportBatch($batchId));
        }

        $this->pairingEntityManager()->clear();
    }

    /**
     * "Add" and the worker until the batch is through.
     *
     * @param null|list<string> $rowIds
     */
    private function addAndWork(DataImport|string $import, Player|string $player, null|array $rowIds = null): AddImportRowsOutcome
    {
        $outcome = $this->add($import, $player, $rowIds);

        if ($outcome->status === AddImportRowsOutcome::STARTED) {
            $this->work($outcome->id);
        }

        return $outcome;
    }

    /**
     * @param null|list<string> $rowIds
     */
    private function undo(
        DataImport|string $import,
        Player|string $by,
        DataImportUndoScope $scope,
        null|string $batchId = null,
        null|array $rowIds = null,
        bool $includeEdited = false,
        bool $asAdmin = false,
        null|string $reason = null,
        null|string $requestId = null,
        bool $work = true,
    ): AddImportRowsOutcome {
        $this->pairingEntityManager()->clear();

        $outcome = self::getContainer()->get(MessageBusInterface::class)->dispatch(new UndoImportRows(
            $requestId ?? Uuid::uuid7()->toString(),
            is_string($import) ? $import : $import->id->toString(),
            is_string($by) ? $by : $by->id->toString(),
            $scope,
            $batchId,
            $rowIds,
            $includeEdited,
            $asAdmin,
            $reason,
        ))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(AddImportRowsOutcome::class, $outcome);
        $this->pairingEntityManager()->clear();

        if ($work && $outcome->status === AddImportRowsOutcome::STARTED && $outcome->id !== null) {
            $this->workUndo($outcome->id);
        }

        return $outcome;
    }

    private function workUndo(string $undoId, int $turns = 100, null|ProcessImportUndoHandler $handler = null): void
    {
        $handler ??= self::getContainer()->get(ProcessImportUndoHandler::class);

        for ($turn = 0; $turn < $turns; $turn++) {
            if ($this->pairingDatabase()->fetchOne('SELECT state FROM data_import_undo WHERE id = :id', ['id' => $undoId]) !== 'running') {
                break;
            }

            $handler(new ProcessImportUndo($undoId));
        }

        $this->pairingEntityManager()->clear();
    }

    /**
     * A result the player typed into the add form - through the form's own message.
     *
     * @param list<string> $people
     */
    private function typedResult(Player $player, string $puzzleId, string $day, int $seconds, array $people = [], bool $firstTry = false): string
    {
        $id = Uuid::uuid7();
        self::assertNotNull($player->userId);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            $id,
            $player->userId,
            $puzzleId,
            null,
            sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60),
            null,
            null,
            $people,
            new DateTimeImmutable($day),
            $firstTry,
            false,
        ));
        $this->pairingEntityManager()->clear();

        return $id->toString();
    }

    /**
     * @return array{state: string, failure: null|string, solving_time_id: null|string, result_made: bool, collection_item_id: null|string, wish_list_item_id: null|string, already_yours_how: null|string, already_yours_solving_time_id: null|string, batch_id: null|string, undo_id: null|string, made: null|array<string, mixed>}
     */
    private function rowNow(string $rowId): array
    {
        $row = $this->pairingDatabase()->fetchAssociative(
            'SELECT state, failure, solving_time_id, result_made, collection_item_id, wish_list_item_id, already_yours_how, already_yours_solving_time_id, batch_id, undo_id, made FROM data_import_row WHERE id = :id',
            ['id' => $rowId],
        );
        self::assertIsArray($row);
        $row['made'] = is_string($row['made']) ? json_decode($row['made'], true, flags: JSON_THROW_ON_ERROR) : null;

        /** @var array{state: string, failure: null|string, solving_time_id: null|string, result_made: bool, collection_item_id: null|string, wish_list_item_id: null|string, already_yours_how: null|string, already_yours_solving_time_id: null|string, batch_id: null|string, undo_id: null|string, made: null|array<string, mixed>} $row */
        return $row;
    }

    /**
     * Why the row removed a wish list item of the player ("remove when solved / when I own it") - null: it removed none.
     */
    private function removedFromWishListWhy(string $rowId): null|string
    {
        $why = $this->pairingDatabase()->fetchOne("SELECT made -> 'removedWishListItems' -> 0 ->> 'why' FROM data_import_row WHERE id = :id", ['id' => $rowId]);

        return is_string($why) ? $why : null;
    }

    /**
     * The player's results as the database has them, oldest solve first.
     *
     * @return list<array<string, mixed>>
     */
    private function resultsOf(Player|string $player, null|string $puzzleId = null): array
    {
        return $this->pairingDatabase()->fetchAllAssociative(
            'SELECT id, puzzle_id, seconds_to_solve, finished_at, tracked_at, puzzling_type, first_attempt, comment, created_via, team, suspicious FROM puzzle_solving_time WHERE player_id = :playerId AND (CAST(:puzzleId AS UUID) IS NULL OR puzzle_id = :puzzleId) ORDER BY finished_at, seconds_to_solve',
            ['playerId' => is_string($player) ? $player : $player->id->toString(), 'puzzleId' => $puzzleId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function batchNow(null|string $batchId): array
    {
        $batch = $this->pairingDatabase()->fetchAssociative('SELECT * FROM data_import_batch WHERE id = :id', ['id' => $batchId]);
        self::assertIsArray($batch);

        return $batch;
    }

    /**
     * @return array<string, mixed>
     */
    private function undoNow(null|string $undoId): array
    {
        $undo = $this->pairingDatabase()->fetchAssociative('SELECT * FROM data_import_undo WHERE id = :id', ['id' => $undoId]);
        self::assertIsArray($undo);

        return $undo;
    }
}
