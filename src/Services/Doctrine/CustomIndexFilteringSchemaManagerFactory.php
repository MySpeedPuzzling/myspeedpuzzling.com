<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\SchemaManagerFactory;
use SpeedPuzzling\Web\Doctrine\CustomIndexFilteringPostgreSQLSchemaManager;

final readonly class CustomIndexFilteringSchemaManagerFactory implements SchemaManagerFactory
{
    /**
     * @return AbstractSchemaManager<AbstractPlatform>
     */
    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            return new CustomIndexFilteringPostgreSQLSchemaManager($connection, $platform);
        }

        return $platform->createSchemaManager($connection);
    }
}
