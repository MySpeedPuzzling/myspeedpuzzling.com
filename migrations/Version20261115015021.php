<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115015021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_row ADD puzzle_key VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_row ADD parsed_version INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_DF686C82B6A263D9DB64AEE8 ON data_import_row (import_id, source_key)');
        $this->addSql('ALTER TABLE data_import_sheet ADD mapping_version INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD parsed_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD rows_understood INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD rows_need_look INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_DF686C82B6A263D9DB64AEE8');
        $this->addSql('ALTER TABLE data_import_row DROP puzzle_key');
        $this->addSql('ALTER TABLE data_import_row DROP parsed_version');
        $this->addSql('ALTER TABLE data_import_sheet DROP mapping_version');
        $this->addSql('ALTER TABLE data_import_sheet DROP parsed_version');
        $this->addSql('ALTER TABLE data_import_sheet DROP rows_understood');
        $this->addSql('ALTER TABLE data_import_sheet DROP rows_need_look');
    }
}
