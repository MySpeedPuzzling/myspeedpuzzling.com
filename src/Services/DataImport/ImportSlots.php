<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Results\ImportHoldingAPlace;
use SpeedPuzzling\Web\Value\DataImport\ImportStanding;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * The player's DataImportLimits::MAX_UNFINISHED_PER_PLAYER places (docs/features/data-import/upload.md "Finished") -
 * **the one place that says which imports count**: every way an import starts (StartImportHandler,
 * ConfirmImportUploadedHandler, CreateImportFromGoogleSheetHandler), "Go on with this import" and the Bring-the-file
 * page ask here.
 *
 * An import holds a place while it is neither discarded nor finished - except one that took rows (so it can never
 * be discarded) and has nothing left: no row ready, nothing waiting for the player, nothing running. Such an import
 * is finished by the daily sweep (FinishSettledImportHandler); it must not lock the player out until then.
 *
 * A read in native SQL: "ready" is the engine's own definition (DataImportRowRepository::sqlReadyRows()), a set of
 * CTEs over the import's rows - never a copy of it. It reads the import's own tables only, and of
 * `puzzle_solving_time` whether a result still exists by its id (whatever its state - a suspicious one exists all
 * the same); nobody's identity is selected.
 */
readonly final class ImportSlots
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The player's imports that hold a place, the oldest first - at most `$atMost` are looked for.
     *
     * @param bool $withUploadsOnTheirWay a new upload is counted against the uploads still on their way too (else
     *        ten started side by side would all pass); the confirmation of one of them is not
     *
     * @return list<ImportHoldingAPlace>
     */
    public function heldBy(string $playerId, bool $withUploadsOnTheirWay = true, int $atMost = DataImportLimits::MAX_UNFINISHED_PER_PLAYER): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        $statuses = $withUploadsOnTheirWay ? DataImportStatus::holdingAPlace() : DataImportStatus::unfinished();

        /** @var list<array{id: string, file_name: string, status: string, took_rows: bool}> $imports */
        $imports = $this->database->executeQuery(<<<SQL
SELECT
    data_import.id,
    data_import.file_name,
    data_import.status,
    EXISTS (SELECT 1 FROM data_import_row r WHERE r.import_id = data_import.id AND r.batch_id IS NOT NULL) AS took_rows
FROM data_import
WHERE data_import.player_id = :playerId
    AND data_import.status IN (:statuses)
ORDER BY data_import.created_at, data_import.id
SQL, [
            'playerId' => $playerId,
            'statuses' => array_map(static fn (DataImportStatus $status): string => $status->value, $statuses),
        ], ['statuses' => ArrayParameterType::STRING])->fetchAllAssociative();

        $held = [];

        foreach ($imports as $import) {
            if (count($held) >= $atMost) {
                break;
            }

            // Only an import that took rows can be settled - any other is looked at no further
            if ($import['took_rows'] && $this->standingOf($import['id'])?->holdsAPlace() === false) {
                continue;
            }

            $held[] = new ImportHoldingAPlace($import['id'], $import['file_name'], DataImportStatus::from($import['status']));
        }

        return $held;
    }

    public function isFullFor(string $playerId, bool $withUploadsOnTheirWay = true): bool
    {
        return count($this->heldBy($playerId, $withUploadsOnTheirWay)) >= DataImportLimits::MAX_UNFINISHED_PER_PLAYER;
    }

    /**
     * What is left to do with the import. One statement, whatever its size.
     */
    public function standingOf(string $importId): null|ImportStanding
    {
        if (Uuid::isValid($importId) === false) {
            return null;
        }

        $ready = DataImportRowRepository::sqlReadyRows();

        /** @var false|array{status: string, wiped: bool, took_rows: bool, working: bool, has_ready: bool, has_waiting: bool, active_at: string} $row */
        $row = $this->database->executeQuery(<<<SQL
WITH {$ready}
SELECT
    data_import.status,
    data_import.cells_wiped_at IS NOT NULL AS wiped,
    EXISTS (SELECT 1 FROM data_import_row r WHERE r.import_id = data_import.id AND r.batch_id IS NOT NULL) AS took_rows,
    (
        EXISTS (SELECT 1 FROM data_import_batch batch WHERE batch.import_id = data_import.id AND batch.state = 'running')
        OR EXISTS (SELECT 1 FROM data_import_undo undo WHERE undo.import_id = data_import.id AND undo.state = 'running')
        OR EXISTS (SELECT 1 FROM data_import_group_answer answer WHERE answer.import_id = data_import.id AND answer.state = 'running')
        OR EXISTS (SELECT 1 FROM data_import_row r WHERE r.import_id = data_import.id AND r.state = 'queued')
    ) AS working,
    EXISTS (SELECT 1 FROM ready) AS has_ready,
    (
        -- A row "Add" would look at whose puzzle waits for the player's choice, or whose result has no date
        EXISTS (SELECT 1 FROM chosen WHERE chosen.choice IN ('undecided', 'catalogue', 'unlisted'))
        -- A row an "Add" took and could not add: it waits with its reason
        OR EXISTS (
            SELECT 1
            FROM data_import_row r
            INNER JOIN data_import_sheet sheet ON sheet.id = r.sheet_id
            WHERE r.import_id = data_import.id
                AND sheet.included
                AND r.batch_id IS NOT NULL
                AND (r.state = 'failed' OR (r.state = 'waiting' AND r.failure IS NOT NULL))
        )
        -- A sheet that is not set up, asks a question or has rows that need a look
        OR EXISTS (
            SELECT 1
            FROM data_import_sheet sheet
            WHERE sheet.import_id = data_import.id
                AND sheet.included
                AND sheet.set_up_failure IS NULL
                AND (
                    sheet.parsed_version IS DISTINCT FROM sheet.mapping_version
                    OR sheet.rows_need_look > 0
                    OR jsonb_array_length(COALESCE(sheet.mapping -> 'questions', '[]'::jsonb)) > 0
                )
        )
        -- The pairing of its puzzles is still on its way
        OR (
            data_import.paired_at IS NULL
            AND data_import.pairing_requeues < :maxRequeues
            AND EXISTS (SELECT 1 FROM data_import_sheet sheet WHERE sheet.import_id = data_import.id AND sheet.parsed_version IS NOT NULL)
        )
    ) AS has_waiting,
    GREATEST(
        data_import.last_activity_at,
        (SELECT MAX(batch.last_activity_at) FROM data_import_batch batch WHERE batch.import_id = data_import.id),
        (SELECT MAX(undo.last_activity_at) FROM data_import_undo undo WHERE undo.import_id = data_import.id),
        (SELECT MAX(answer.last_activity_at) FROM data_import_group_answer answer WHERE answer.import_id = data_import.id)
    ) AS active_at
FROM data_import
WHERE data_import.id = :importId
SQL, [
            'importId' => $importId,
            'explicit' => 0,
            'maxRequeues' => DataImportLimits::MAX_SET_UP_ATTEMPTS,
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        // Rows whose content was wiped can not be added or answered any more: nothing of them is ready or waits
        return new ImportStanding(
            status: DataImportStatus::from($row['status']),
            tookRows: $row['took_rows'],
            working: $row['working'],
            hasReady: $row['wiped'] === false && $row['has_ready'],
            hasWaiting: $row['wiped'] === false && $row['has_waiting'],
            activeAt: new DateTimeImmutable($row['active_at']),
        );
    }

    /**
     * Imports the daily sweep looks at: set up, took rows, nothing running. In another order every day, so that
     * imports that stay as they are never keep the sweep from the ones behind them.
     *
     * @return list<array{importId: string, playerId: string}>
     */
    public function toLookAt(DateTimeImmutable $now, int $limit): array
    {
        /** @var list<array{id: string, player_id: string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT data_import.id, data_import.player_id
FROM data_import
WHERE data_import.status IN ('setting_up', 'reviewing')
    AND EXISTS (SELECT 1 FROM data_import_row r WHERE r.import_id = data_import.id AND r.batch_id IS NOT NULL)
    AND NOT EXISTS (SELECT 1 FROM data_import_batch batch WHERE batch.import_id = data_import.id AND batch.state = 'running')
ORDER BY md5(data_import.id::text || :day)
LIMIT :limit
SQL, ['day' => $now->format('Y-m-d'), 'limit' => $limit])->fetchAllAssociative();

        return array_map(static fn (array $row): array => ['importId' => $row['id'], 'playerId' => $row['player_id']], $rows);
    }
}
