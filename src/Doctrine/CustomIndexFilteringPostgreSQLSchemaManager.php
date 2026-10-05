<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\PostgreSQLSchemaManager;

final class CustomIndexFilteringPostgreSQLSchemaManager extends PostgreSQLSchemaManager
{
    private const string CUSTOM_INDEX_PREFIX = 'custom_';

    /**
     * Columns no longer mapped that the next release drops. Blue-green: the release that stops using a column keeps
     * it, because the containers of the release before still read and write it until they are gone - so the schema
     * tools must not see it meanwhile (schema:validate, migrations:diff). An entry goes with its column's DROP.
     * puzzle.alternative_name: dropped in phase 1c-2 (docs/features/puzzle-names/).
     */
    private const array UNMAPPED_COLUMNS_AWAITING_DROP = [
        'puzzle' => ['alternative_name'],
    ];

    public function __construct(Connection $connection, PostgreSQLPlatform $platform)
    {
        parent::__construct($connection, $platform);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchTableColumns(string $databaseName, null|string $tableName = null): array
    {
        $tableColumns = parent::fetchTableColumns($databaseName, $tableName);

        return array_values(array_filter(
            $tableColumns,
            static function (array $row): bool {
                $table = $row['table_name'] ?? null;

                if (!is_string($table) || !array_key_exists($table, self::UNMAPPED_COLUMNS_AWAITING_DROP)) {
                    return true;
                }

                return !in_array($row['field'] ?? null, self::UNMAPPED_COLUMNS_AWAITING_DROP[$table], true);
            }
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchIndexColumns(string $databaseName, null|string $tableName = null): array
    {
        $indexColumns = parent::fetchIndexColumns($databaseName, $tableName);

        return array_values(array_filter(
            $indexColumns,
            static function (array $row): bool {
                $indexName = $row['relname'] ?? '';

                if (!is_string($indexName)) {
                    return true;
                }

                return !str_starts_with(strtolower($indexName), self::CUSTOM_INDEX_PREFIX);
            }
        ));
    }
}
