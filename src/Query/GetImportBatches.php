<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportSettings;

/**
 * The admins' view of what the spreadsheet imports add (/admin/imports, docs/features/data-import/adding.md "The
 * audit page"): the latest imports, "Add"s and Undos across players, and one batch's rows by outcome. Admins only -
 * /admin sees everyone, so no blocklist or private-profile rule applies. States, numbers, ids and the file's name:
 * never a cell's content, never a value of a player's own fields.
 *
 * It reads the import's own tables and `player` for a name; nothing of `puzzle_solving_time`.
 */
readonly final class GetImportBatches
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{adding_paused: bool, changed_at: null|string, changed_by_name: null|string, reason: null|string}
     */
    public function addingSwitch(): array
    {
        /** @var false|array{adding_paused: bool, changed_at: null|string, changed_by_name: null|string, reason: null|string} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    settings.adding_paused,
    settings.adding_paused_changed_at AS changed_at,
    COALESCE(admin.name, '#' || admin.code) AS changed_by_name,
    settings.adding_paused_reason AS reason
FROM data_import_settings settings
LEFT JOIN player admin ON admin.id = settings.adding_paused_changed_by
WHERE settings.id = :id
SQL, ['id' => DataImportSettings::ID])->fetchAssociative();

        return $row === false
            ? ['adding_paused' => false, 'changed_at' => null, 'changed_by_name' => null, 'reason' => null]
            : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latestImports(int $limit = 30): array
    {
        return $this->database->executeQuery(<<<SQL
SELECT
    data_import.id,
    data_import.file_name,
    data_import.file_kind,
    data_import.source,
    data_import.status,
    data_import.rows_read,
    data_import.created_at,
    data_import.last_activity_at,
    player.id AS player_id,
    COALESCE(player.name, '#' || player.code) AS player_name,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.import_id = data_import.id AND r.state = 'added') AS rows_added,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.import_id = data_import.id AND r.state = 'queued') AS rows_queued,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.import_id = data_import.id AND r.state = 'failed') AS rows_failed
FROM data_import
INNER JOIN player ON player.id = data_import.player_id
ORDER BY data_import.last_activity_at DESC, data_import.id DESC
LIMIT :limit
SQL, ['limit' => $limit])->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latestBatches(int $limit = 50): array
    {
        return $this->database->executeQuery($this->batchSql('TRUE') . ' ORDER BY batch.started_at DESC, batch.id DESC LIMIT :limit', ['limit' => $limit])->fetchAllAssociative();
    }

    /**
     * @return null|array<string, mixed>
     */
    public function batch(string $batchId): null|array
    {
        if (Uuid::isValid($batchId) === false) {
            return null;
        }

        $batch = $this->database->executeQuery($this->batchSql('batch.id = :batchId'), ['batchId' => $batchId])->fetchAssociative();

        return $batch === false ? null : $batch;
    }

    /**
     * The rows a batch last took, the ones that were not added first. What a row made by its ids - never its cells.
     *
     * @return list<array<string, mixed>>
     */
    public function rowsOfBatch(string $batchId, int $limit = 500): array
    {
        if (Uuid::isValid($batchId) === false) {
            return [];
        }

        return $this->database->executeQuery(<<<SQL
SELECT
    r.id,
    sheet.title AS sheet_title,
    r.row_number,
    r.kind,
    CASE
        WHEN r.state = 'waiting' AND r.failure IS NOT NULL THEN 'refused'
        WHEN r.state = 'waiting' THEN 'waiting_again'
        ELSE r.state
    END AS outcome,
    r.failure,
    r.already_yours_how,
    r.added_at,
    CASE WHEN r.result_made THEN r.solving_time_id END AS solving_time_id,
    r.collection_item_id,
    r.wish_list_item_id,
    r.made ->> 'keptByUndo' AS kept_by_undo,
    jsonb_array_length(COALESCE(r.made -> 'removedWishListItems', '[]'::jsonb)) AS wish_list_items_removed
FROM data_import_row r
INNER JOIN data_import_sheet sheet ON sheet.id = r.sheet_id
WHERE r.batch_id = :batchId
ORDER BY
    CASE r.state WHEN 'failed' THEN 0 WHEN 'waiting' THEN 1 WHEN 'queued' THEN 2 WHEN 'added' THEN 3 ELSE 4 END,
    sheet.position,
    r.row_number
LIMIT :limit
SQL, ['batchId' => $batchId, 'limit' => $limit])->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latestUndos(int $limit = 30, null|string $batchId = null): array
    {
        $where = $batchId === null ? 'TRUE' : 'undo.batch_id = :batchId';

        return $this->database->executeQuery(<<<SQL
SELECT
    undo.id,
    undo.scope,
    undo.batch_id,
    undo.state,
    undo.started_at,
    undo.finished_at,
    undo.by_admin,
    undo.reason,
    undo.rows_requested,
    undo.rows_undone,
    undo.results_deleted,
    undo.results_kept_edited,
    undo.collection_items_removed,
    undo.collection_items_kept,
    undo.wish_list_items_removed,
    undo.wish_list_items_restored,
    undo.rows_failed,
    data_import.id AS import_id,
    data_import.file_name,
    COALESCE(owner.name, '#' || owner.code) AS player_name,
    owner.id AS player_id,
    COALESCE(asked.name, '#' || asked.code) AS requested_by_name
FROM data_import_undo undo
INNER JOIN data_import ON data_import.id = undo.import_id
INNER JOIN player owner ON owner.id = data_import.player_id
LEFT JOIN player asked ON asked.id = undo.requested_by_player_id
WHERE {$where}
ORDER BY undo.started_at DESC, undo.id DESC
LIMIT :limit
SQL, $batchId === null ? ['limit' => $limit] : ['limit' => $limit, 'batchId' => $batchId])->fetchAllAssociative();
    }

    private function batchSql(string $where): string
    {
        return <<<SQL
SELECT
    batch.id,
    batch.state,
    batch.started_at,
    batch.finished_at,
    batch.last_activity_at,
    batch.undone_at,
    batch.rows_requested,
    batch.rows_added,
    batch.rows_already_yours,
    batch.rows_refused,
    batch.rows_failed,
    batch.results_added,
    batch.collection_items_added,
    batch.wish_list_items_added,
    batch.skipped,
    (SELECT COUNT(*) FROM data_import_row r WHERE r.batch_id = batch.id AND r.state = 'queued') AS rows_queued,
    data_import.id AS import_id,
    data_import.file_name,
    data_import.file_kind,
    player.id AS player_id,
    COALESCE(player.name, '#' || player.code) AS player_name
FROM data_import_batch batch
INNER JOIN data_import ON data_import.id = batch.import_id
INNER JOIN player ON player.id = data_import.player_id
WHERE {$where}
SQL;
    }
}
