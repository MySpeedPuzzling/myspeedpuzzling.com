<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Doctrine;

use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/** @see https://github.com/doctrine/migrations/issues/1406 */
readonly final class FixDoctrineMigrationTableSchema
{
    private TableMetadataStorageConfiguration $configuration;

    public function __construct(
        private DependencyFactory $dependencyFactory,
    ) {
        $configuration = $this->dependencyFactory->getConfiguration()->getMetadataStorageConfiguration();

        assert($configuration !== null);
        assert($configuration instanceof TableMetadataStorageConfiguration);

        $this->configuration = $configuration;
    }

    /**
     * @throws SchemaException
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        $table = $schema->createTable($this->configuration->getTableName());
        $table->addColumn(
            $this->configuration->getVersionColumnName(),
            'string',
            ['notnull' => true, 'length' => $this->configuration->getVersionColumnLength()],
        );
        $table->addColumn($this->configuration->getExecutedAtColumnName(), 'datetime', ['notnull' => false]);
        $table->addColumn($this->configuration->getExecutionTimeColumnName(), 'integer', ['notnull' => false]);

        $table->setPrimaryKey([$this->configuration->getVersionColumnName()]);
    }
}
