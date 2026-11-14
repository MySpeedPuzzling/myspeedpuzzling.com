<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Results\DataImportOverview;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;

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
    data_import.file_key IS NOT NULL AS file_kept,
    data_import.file_removed_at
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The newest first. Without the uploads that never finished (the browser is still sending, or gave up - the daily
     * prune removes those) and without discarded imports.
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
    AND data_import.status NOT IN (:uploading, :discarded)
ORDER BY data_import.created_at DESC, data_import.id DESC
LIMIT 200
SQL;

        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'uploading' => DataImportStatus::Uploading->value,
            'discarded' => DataImportStatus::Discarded->value,
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
         *     file_name: string,
         *     file_size: null|int|string,
         *     status: string,
         *     created_at: string,
         *     file_kept: bool,
         *     file_removed_at: null|string,
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
        );
    }
}
