<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115083618 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_import_group_answer (state VARCHAR(16) NOT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_row_id UUID DEFAULT NULL, rows_done INT DEFAULT 0 NOT NULL, rows_answered INT DEFAULT 0 NOT NULL, answered_row_ids JSONB NOT NULL, batch_id UUID DEFAULT NULL, add_status VARCHAR(32) DEFAULT NULL, id UUID NOT NULL, code VARCHAR(32) NOT NULL, action VARCHAR(16) NOT NULL, of_batch_id UUID DEFAULT NULL, before_add BOOLEAN NOT NULL, rows_total INT NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, import_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1F999205B6A263D9 ON data_import_group_answer (import_id)');
        $this->addSql('CREATE INDEX IDX_1F999205B6A263D9D46F4E3 ON data_import_group_answer (import_id, started_at)');
        $this->addSql('ALTER TABLE data_import_group_answer ADD CONSTRAINT FK_1F999205B6A263D9 FOREIGN KEY (import_id) REFERENCES data_import (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_group_answer DROP CONSTRAINT FK_1F999205B6A263D9');
        $this->addSql('DROP TABLE data_import_group_answer');
    }
}
