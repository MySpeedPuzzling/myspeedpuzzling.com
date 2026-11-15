<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;

/**
 * How far the latest "Add" of an import is (docs/features/data-import/adding.md "Progress") - what its page shows
 * while the worker goes through the rows, and what ImportProgress publishes to the player's own topic. Numbers and
 * states only, from the import's own tables: nothing of the file, nothing of anybody's results.
 */
readonly final class GetImportAdding
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * For the import's own page.
     *
     * @return null|array{type: string, importId: string, batchId: string, state: string, paused: bool, rows: int, queued: int, added: int, alreadyYours: int, notAdded: int}
     *
     * @throws DataImportNotFound
     */
    public function ofPlayersImport(string $importId, string $playerId): null|array
    {
        $adding = Uuid::isValid($playerId) ? $this->ofImport($importId) : null;

        if ($adding === null) {
            return null;
        }

        if ($adding['playerId'] !== strtolower($playerId)) {
            throw new DataImportNotFound();
        }

        return $adding['payload'];
    }

    /**
     * For whoever publishes it to the player's own topic (ImportProgress) - never for a page: no player is checked.
     *
     * @return null|array{playerId: string, payload: array{type: string, importId: string, batchId: string, state: string, paused: bool, rows: int, queued: int, added: int, alreadyYours: int, notAdded: int}}
     */
    public function ofImport(string $importId): null|array
    {
        if (Uuid::isValid($importId) === false) {
            return null;
        }

        /** @var false|array{player_id: string, batch_id: string, state: string, paused: bool, rows_requested: int, queued: int, added: int, already_yours: int, not_added: int} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    data_import.player_id,
    batch.id AS batch_id,
    batch.state,
    COALESCE((SELECT settings.adding_paused FROM data_import_settings settings LIMIT 1), FALSE) AS paused,
    batch.rows_requested,
    COUNT(r.id) FILTER (WHERE r.state = 'queued') AS queued,
    COUNT(r.id) FILTER (WHERE r.state = 'added') AS added,
    COUNT(r.id) FILTER (WHERE r.state = 'already_yours') AS already_yours,
    COUNT(r.id) FILTER (WHERE r.state = 'failed' OR (r.state = 'waiting' AND r.failure IS NOT NULL)) AS not_added
FROM data_import_batch batch
INNER JOIN data_import ON data_import.id = batch.import_id
LEFT JOIN data_import_row r ON r.batch_id = batch.id
WHERE batch.import_id = :importId
GROUP BY data_import.player_id, batch.id
ORDER BY batch.started_at DESC, batch.id DESC
LIMIT 1
SQL, ['importId' => $importId])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'playerId' => $row['player_id'],
            'payload' => [
                'type' => 'import_adding',
                'importId' => strtolower($importId),
                'batchId' => $row['batch_id'],
                'state' => $row['state'],
                'paused' => $row['paused'] && $row['state'] === 'running',
                'rows' => $row['rows_requested'],
                'queued' => $row['queued'],
                'added' => $row['added'],
                'alreadyYours' => $row['already_yours'],
                'notAdded' => $row['not_added'],
            ],
        ];
    }
}
