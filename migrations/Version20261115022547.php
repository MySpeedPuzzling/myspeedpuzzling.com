<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115022547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_row ADD equal_key VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD parsing_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD parsing_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD set_up_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import_sheet ADD set_up_failure VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_row DROP equal_key');
        $this->addSql('ALTER TABLE data_import_sheet DROP parsing_version');
        $this->addSql('ALTER TABLE data_import_sheet DROP parsing_started_at');
        $this->addSql('ALTER TABLE data_import_sheet DROP set_up_attempts');
        $this->addSql('ALTER TABLE data_import_sheet DROP set_up_failure');
    }
}
