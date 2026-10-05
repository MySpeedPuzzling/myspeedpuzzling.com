<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\PostgreSQLSchemaManager;

final class CustomIndexFilteringPostgreSQLSchemaManager extends PostgreSQLSchemaManager
{
    private const string CUSTOM_INDEX_PREFIX = 'custom_';

    public function __construct(Connection $connection, PostgreSQLPlatform $platform)
    {
        parent::__construct($connection, $platform);
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
