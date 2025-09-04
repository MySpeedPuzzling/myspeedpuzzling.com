<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add currency column to puzzle_collection_item table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_collection_item ADD currency VARCHAR(3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_collection_item DROP COLUMN currency');
    }
}
