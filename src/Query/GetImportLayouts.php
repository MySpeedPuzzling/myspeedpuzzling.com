<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Results\ImportLayoutOverview;
use SpeedPuzzling\Web\Services\DataImport\ImportLayouts;
use SpeedPuzzling\Web\Value\DataImport\ImportLayoutContent;
use SpeedPuzzling\Web\Value\DataImport\SavedImportLayout;

/**
 * A player's saved layouts (docs/features/data-import/layouts.md): their own list, how many there are, and - for
 * the import that proposes a mapping - the layouts of the import's own player. Never another player's: every
 * statement names the player, or reaches them through the import that is theirs.
 */
readonly final class GetImportLayouts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<ImportLayoutOverview> the one used last first
     */
    public function ofPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        /** @var list<array{id: string, name: string, columns: int, times_used: int, last_used_at: string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT id, name, jsonb_array_length(headers) AS columns, times_used, last_used_at
FROM import_layout
WHERE player_id = :playerId
ORDER BY last_used_at DESC, id DESC
LIMIT :limit
SQL, ['playerId' => $playerId, 'limit' => ImportLayouts::MAX_PER_PLAYER])->fetchAllAssociative();

        return array_map(static fn (array $row): ImportLayoutOverview => new ImportLayoutOverview(
            layoutId: $row['id'],
            name: $row['name'],
            columns: $row['columns'],
            timesUsed: $row['times_used'],
            lastUsedAt: new DateTimeImmutable($row['last_used_at']),
        ), $rows);
    }

    public function countOfPlayer(string $playerId): int
    {
        if (Uuid::isValid($playerId) === false) {
            return 0;
        }

        /** @var int $count */
        $count = $this->database->executeQuery('SELECT COUNT(*) FROM import_layout WHERE player_id = :playerId', ['playerId' => $playerId])->fetchOne();

        return $count;
    }

    /**
     * The layouts of the player an import belongs to - what a proposal of its sheets may apply. A layout stored in a
     * shape this version can not read is left out.
     *
     * @return list<SavedImportLayout>
     */
    public function ofImportsPlayer(string $importId): array
    {
        if (Uuid::isValid($importId) === false) {
            return [];
        }

        /** @var list<array{id: string, name: string, signature: string, headers: string, content: string, changes_known_export: bool, last_used_at: string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT layout.id, layout.name, layout.signature, layout.headers, layout.content, layout.changes_known_export, layout.last_used_at
FROM import_layout layout
INNER JOIN data_import ON data_import.player_id = layout.player_id
WHERE data_import.id = :importId
ORDER BY layout.last_used_at DESC, layout.id DESC
LIMIT :limit
SQL, ['importId' => $importId, 'limit' => ImportLayouts::MAX_PER_PLAYER])->fetchAllAssociative();

        $layouts = [];

        foreach ($rows as $row) {
            $headers = json_decode($row['headers'], true);
            $content = json_decode($row['content'], true);

            if (is_array($headers) === false || is_array($content) === false) {
                continue;
            }

            try {
                $layouts[] = new SavedImportLayout(
                    $row['id'],
                    $row['name'],
                    $row['signature'],
                    array_values(array_filter($headers, is_string(...))),
                    ImportLayoutContent::fromArray($content),
                    $row['changes_known_export'],
                    new DateTimeImmutable($row['last_used_at']),
                );
            } catch (InvalidImportMapping) {
                continue;
            }
        }

        return $layouts;
    }
}
