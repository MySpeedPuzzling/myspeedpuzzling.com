<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114230332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import ADD reading_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import ADD read_finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import ADD read_attempt INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import ADD sheets_total INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import ADD sheets_read INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import ADD rows_read INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import ADD same_file_as_import_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD hidden BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD columns_total INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD hints JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import DROP reading_started_at');
        $this->addSql('ALTER TABLE data_import DROP read_finished_at');
        $this->addSql('ALTER TABLE data_import DROP read_attempt');
        $this->addSql('ALTER TABLE data_import DROP sheets_total');
        $this->addSql('ALTER TABLE data_import DROP sheets_read');
        $this->addSql('ALTER TABLE data_import DROP rows_read');
        $this->addSql('ALTER TABLE data_import DROP same_file_as_import_id');
        $this->addSql('ALTER TABLE data_import_sheet DROP hidden');
        $this->addSql('ALTER TABLE data_import_sheet DROP columns_total');
        $this->addSql('ALTER TABLE data_import_sheet DROP hints');
    }
}
