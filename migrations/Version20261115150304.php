<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115150304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import ADD standing_answers JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_group_answer ADD rows_still_waiting INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import_group_answer ADD also JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_row ADD open_questions JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import DROP standing_answers');
        $this->addSql('ALTER TABLE data_import_group_answer DROP rows_still_waiting');
        $this->addSql('ALTER TABLE data_import_group_answer DROP also');
        $this->addSql('ALTER TABLE data_import_row DROP open_questions');
    }
}
