<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Results\DataImportOverview;
use SpeedPuzzling\Web\Results\DataImportProgress;
use SpeedPuzzling\Web\Results\DataImportSheetOverview;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportReadFailure;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\DataImportSource;
use SpeedPuzzling\Web\Value\DataImportStatus;
use SpeedPuzzling\Web\Value\GoogleSheetFetchFailure;

/**
 * The signed-in player's own imports (docs/features/data-import/upload.md) - for their own pages only: the caller
 * passes the id of the player who is signed in, never an id from a URL. Another player's import is "not found".
 */
readonly final class GetDataImports
{
    private const string COLUMNS = <<<SQL
    data_import.id,
    data_import.file_name,
    data_import.file_size,
    data_import.status,
    data_import.created_at,
    data_import.source,
    -- A Google sheet has its key from the start and its file only once it was fetched
    (data_import.file_key IS NOT NULL AND data_import.status NOT IN ('fetching', 'fetch_failed')) AS file_kept,
    data_import.file_removed_at,
    data_import.read_error,
    data_import.sheets_total,
    data_import.sheets_read,
    data_import.rows_read
SQL;

    /** How long after its last sign of life a pairing run is still followed by an open page */
    private const int PAIRING_FOLLOWED_MINUTES = 20;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The newest first. Without discarded imports, and without an upload started a moment ago (the browser is still
     * sending it): after DataImportLimits::UPLOADING_SHOWN_AFTER_SECONDS it is listed as one to finish - the file
     * may have arrived although the confirmation never did, and the player must not be left with a hidden import.
     *
     * @return list<DataImportOverview>
     */
    public function ofPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        $columns = self::COLUMNS;
        $query = <<<SQL
SELECT
{$columns}
FROM data_import
WHERE data_import.player_id = :playerId
    AND data_import.status <> :discarded
    AND (data_import.status <> :uploading OR data_import.created_at < CAST(:uploadingBefore AS timestamp))
ORDER BY data_import.created_at DESC, data_import.id DESC
LIMIT 200
SQL;

        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'uploading' => DataImportStatus::Uploading->value,
            'discarded' => DataImportStatus::Discarded->value,
            'uploadingBefore' => $this->clock->now()->modify('-' . DataImportLimits::UPLOADING_SHOWN_AFTER_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        return array_map(self::overview(...), $rows);
    }

    /**
     * @throws DataImportNotFound no such import, a discarded one, or another player's
     */
    public function byIdOfPlayer(string $importId, string $playerId): DataImportOverview
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            throw new DataImportNotFound();
        }

        $columns = self::COLUMNS;
        $query = <<<SQL
SELECT
{$columns}
FROM data_import
WHERE data_import.id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> :discarded
SQL;

        $row = $this->database->executeQuery($query, [
            'importId' => $importId,
            'playerId' => $playerId,
            'discarded' => DataImportStatus::Discarded->value,
        ])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        return self::overview($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function overview(array $row): DataImportOverview
    {
        /**
         * @var array{
         *     id: string,
         *     source: string,
         *     file_name: string,
         *     file_size: null|int|string,
         *     status: string,
         *     created_at: string,
         *     file_kept: bool,
         *     file_removed_at: null|string,
         *     read_error: null|string,
         *     sheets_total: null|int,
         *     sheets_read: int,
         *     rows_read: int,
         * } $row
         */
        $createdAt = new DateTimeImmutable($row['created_at']);

        return new DataImportOverview(
            importId: $row['id'],
            fileName: $row['file_name'],
            fileSize: $row['file_size'] !== null ? (int) $row['file_size'] : null,
            status: DataImportStatus::from($row['status']),
            createdAt: $createdAt,
            fileKept: $row['file_kept'],
            fileRemovedAt: $row['file_removed_at'] !== null ? new DateTimeImmutable($row['file_removed_at']) : null,
            fileKeptUntil: $row['file_kept'] ? $createdAt->modify('+' . DataImportLimits::KEEP_FILE_DAYS . ' days') : null,
            readFailure: $row['status'] === DataImportStatus::ReadFailed->value ? DataImportReadFailure::shown($row['read_error']) : null,
            sheetsTotal: $row['sheets_total'],
            sheetsRead: $row['sheets_read'],
            rowsRead: $row['rows_read'],
            fetchFailure: $row['status'] === DataImportStatus::FetchFailed->value ? GoogleSheetFetchFailure::shown($row['read_error']) : null,
            fromGoogleSheet: $row['source'] === DataImportSource::GoogleSheet->value,
        );
    }

    /**
     * How far the player's own import is - what its page asks for while the file is read. An import that was closed
     * because the player already has the very same file answers `same_file` and names that import; any other
     * discarded one is "not found".
     *
     * @throws DataImportNotFound
     */
    public function progressOfPlayer(string $importId, string $playerId): DataImportProgress
    {
        $progress = Uuid::isValid($playerId) ? $this->progress($importId) : null;

        if ($progress === null || $progress->playerId !== strtolower($playerId)) {
            throw new DataImportNotFound();
        }

        return $progress;
    }

    /**
     * The same for whoever publishes it to the player's own topic (ImportProgress) - never for a page: no player is
     * checked here.
     */
    public function progress(string $importId): null|DataImportProgress
    {
        if (Uuid::isValid($importId) === false) {
            return null;
        }

        $query = <<<SQL
SELECT
    data_import.id,
    data_import.player_id,
    data_import.status,
    data_import.read_error,
    data_import.sheets_total,
    data_import.sheets_read,
    data_import.rows_read,
    same_file.id AS same_file_as,
    (SELECT COUNT(*) FROM data_import_sheet sheet WHERE sheet.import_id = data_import.id AND sheet.mapping_version > 0) AS sheets_mapped,
    (SELECT COUNT(*) FROM data_import_sheet sheet WHERE sheet.import_id = data_import.id AND sheet.mapping_version > 0 AND sheet.parsed_version = sheet.mapping_version) AS sheets_parsed,
    (SELECT COUNT(*) FROM data_import_sheet sheet WHERE sheet.import_id = data_import.id AND sheet.mapping_version > 0 AND jsonb_array_length(COALESCE(sheet.mapping -> 'questions', '[]'::jsonb)) > 0) AS sheets_waiting,
    -- What still happens by itself while the import is set up (docs/features/data-import/columns-screen.md "The live
    -- line"): sheets without a mapping yet, and sheets whose rows are read for their current mapping
    (SELECT COUNT(*) FROM data_import_sheet sheet WHERE sheet.import_id = data_import.id AND sheet.set_up_failure IS NULL AND (sheet.mapping_version = 0 OR (sheet.parsed_version IS DISTINCT FROM sheet.mapping_version))) AS sheets_working,
    (SELECT COALESCE(SUM(sheet.rows_total), 0) FROM data_import_sheet sheet WHERE sheet.import_id = data_import.id AND sheet.set_up_failure IS NULL AND sheet.mapping_version > 0 AND sheet.parsed_version IS DISTINCT FROM sheet.mapping_version) AS rows_to_parse,
    (SELECT COUNT(*) FROM data_import_sheet sheet INNER JOIN data_import_row parsed_row ON parsed_row.sheet_id = sheet.id AND parsed_row.parsed_version = sheet.mapping_version WHERE sheet.import_id = data_import.id AND sheet.set_up_failure IS NULL AND sheet.mapping_version > 0 AND sheet.parsed_version IS DISTINCT FROM sheet.mapping_version) AS rows_parsed,
    -- The pairing of the import's puzzles with the catalogue (docs/features/data-import/pairing-screens.md "The live
    -- line"): numbers by what is left for the player to do, as GetImportPairing::summary() counts them
    data_import.pairing_run,
    data_import.paired_at IS NOT NULL AS paired,
    (
        data_import.paired_at IS NULL
        AND data_import.cells_wiped_at IS NULL
        AND data_import.pairing_requeues < :maxRequeues
        AND data_import.last_activity_at > :pairingAliveSince
        AND EXISTS (SELECT 1 FROM data_import_sheet sheet WHERE sheet.import_id = data_import.id AND sheet.parsed_version IS NOT NULL)
    ) AS pairing_running,
    (SELECT COUNT(*) FROM data_import_puzzle puzzle WHERE puzzle.import_id = data_import.id) AS puzzles,
    (SELECT COUNT(*) FROM data_import_puzzle puzzle WHERE puzzle.import_id = data_import.id AND puzzle.choice = 'catalogue') AS puzzles_found,
    (SELECT COUNT(*) FROM data_import_puzzle puzzle WHERE puzzle.import_id = data_import.id AND puzzle.choice = 'undecided' AND puzzle.tier = 'probably') AS puzzles_probably,
    (SELECT COUNT(*) FROM data_import_puzzle puzzle WHERE puzzle.import_id = data_import.id AND puzzle.choice = 'undecided' AND puzzle.tier IN ('choose', 'found')) AS puzzles_choose,
    (SELECT COUNT(*) FROM data_import_puzzle puzzle WHERE puzzle.import_id = data_import.id AND (puzzle.choice = 'unlisted' OR (puzzle.choice = 'undecided' AND puzzle.tier = 'none'))) AS puzzles_not_found,
    (SELECT COUNT(*) FROM data_import_puzzle puzzle WHERE puzzle.import_id = data_import.id AND puzzle.choice = 'undecided' AND puzzle.tier IS NULL) AS puzzles_searching,
    -- Changes whenever a sheet's state does: the screen loads its lists again when it sees another one
    (SELECT md5(COALESCE(string_agg(sheet.id::text || ':' || sheet.mapping_version || ':' || COALESCE(sheet.parsed_version, 0) || ':' || COALESCE(sheet.set_up_failure, ''), ',' ORDER BY sheet.position), '')) FROM data_import_sheet sheet WHERE sheet.import_id = data_import.id) AS revision
FROM data_import
LEFT JOIN data_import same_file
    ON same_file.id = data_import.same_file_as_import_id
    AND same_file.player_id = data_import.player_id
    AND same_file.status <> :discarded
WHERE data_import.id = :importId
SQL;

        /** @var false|array{id: string, player_id: string, status: string, read_error: null|string, sheets_total: null|int, sheets_read: int, rows_read: int, same_file_as: null|string, sheets_mapped: int, sheets_parsed: int, sheets_waiting: int, sheets_working: int, rows_to_parse: int|string, rows_parsed: int, revision: string, pairing_run: int, paired: bool, pairing_running: bool, puzzles: int, puzzles_found: int, puzzles_probably: int, puzzles_choose: int, puzzles_not_found: int, puzzles_searching: int} $row */
        $row = $this->database->executeQuery($query, [
            'importId' => $importId,
            'discarded' => DataImportStatus::Discarded->value,
            'maxRequeues' => DataImportLimits::MAX_SET_UP_ATTEMPTS,
            // A run that wrote nothing for this long is not waited for by a page - the safety net tries it again
            'pairingAliveSince' => $this->clock->now()->modify('-' . self::PAIRING_FOLLOWED_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $discarded = $row['status'] === DataImportStatus::Discarded->value;

        if ($discarded && $row['same_file_as'] === null) {
            return null;
        }

        return new DataImportProgress(
            importId: $row['id'],
            playerId: $row['player_id'],
            status: $discarded ? DataImportProgress::SAME_FILE : $row['status'],
            sheetsTotal: $row['sheets_total'],
            sheetsRead: $row['sheets_read'],
            rowsRead: $row['rows_read'],
            readFailure: $row['status'] === DataImportStatus::ReadFailed->value ? DataImportReadFailure::shown($row['read_error']) : null,
            sameFileAsImportId: $discarded ? $row['same_file_as'] : null,
            sheetsMapped: $row['sheets_mapped'],
            sheetsParsed: $row['sheets_parsed'],
            sheetsWaiting: $row['sheets_waiting'],
            sheetsWorking: $row['status'] === DataImportStatus::SettingUp->value ? $row['sheets_working'] : 0,
            rowsToParse: (int) $row['rows_to_parse'],
            rowsParsed: $row['rows_parsed'],
            revision: $row['revision'],
            pairing: [
                'running' => $row['status'] === DataImportStatus::SettingUp->value && $row['pairing_running'],
                'puzzles' => $row['puzzles'],
                'found' => $row['puzzles_found'],
                'probably' => $row['puzzles_probably'],
                'choose' => $row['puzzles_choose'],
                'notFound' => $row['puzzles_not_found'],
                'searching' => $row['puzzles_searching'],
                // Another one whenever a run starts or comes to rest: the puzzles' pages load their lists again
                'revision' => $row['pairing_run'] . ':' . ($row['paired'] ? '1' : '0'),
            ],
        );
    }

    /**
     * The sheets of the player's own import as the file has them - an import that was not read has none.
     *
     * @return list<DataImportSheetOverview>
     */
    public function sheetsOfPlayer(string $importId, string $playerId): array
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT
    data_import_sheet.id,
    data_import_sheet.position,
    data_import_sheet.title,
    data_import_sheet.hidden,
    data_import_sheet.rows_total,
    data_import_sheet.columns_total,
    data_import_sheet.kind,
    data_import_sheet.mapping_version,
    data_import_sheet.parsed_version,
    data_import_sheet.rows_understood,
    data_import_sheet.rows_need_look,
    data_import_sheet.rows_left_out,
    data_import_sheet.rows_waiting,
    data_import_sheet.set_up_failure IS NOT NULL AS failed,
    data_import_sheet.set_up_failure,
    jsonb_array_length(COALESCE(data_import_sheet.mapping -> 'questions', '[]'::jsonb)) AS questions
FROM data_import_sheet
INNER JOIN data_import ON data_import.id = data_import_sheet.import_id
WHERE data_import_sheet.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> :discarded
ORDER BY data_import_sheet.position
SQL;

        /** @var list<array{id: string, position: int, title: string, hidden: bool, rows_total: int, columns_total: int, kind: null|string, mapping_version: int, parsed_version: null|int, rows_understood: int, rows_need_look: int, rows_left_out: int, rows_waiting: int, failed: bool, set_up_failure: null|string, questions: int}> $rows */
        $rows = $this->database->executeQuery($query, [
            'importId' => $importId,
            'playerId' => $playerId,
            'discarded' => DataImportStatus::Discarded->value,
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): DataImportSheetOverview => new DataImportSheetOverview(
            position: $row['position'],
            title: $row['title'],
            hidden: $row['hidden'],
            rows: $row['rows_total'],
            columns: $row['columns_total'],
            kind: $row['kind'] === null ? null : DataImportSheetKind::tryFrom($row['kind']),
            mapped: $row['mapping_version'] > 0,
            waitsForAnAnswer: $row['questions'] > 0,
            parsed: $row['mapping_version'] > 0 && $row['parsed_version'] === $row['mapping_version'],
            rowsUnderstood: $row['rows_understood'],
            rowsNeedLook: $row['rows_need_look'],
            rowsLeftOut: $row['rows_left_out'],
            failed: $row['failed'],
            sheetId: $row['id'],
            mappingVersion: $row['mapping_version'],
            failure: $row['set_up_failure'],
            rowsWaiting: $row['rows_waiting'],
            questions: $row['questions'],
        ), $rows);
    }
}
