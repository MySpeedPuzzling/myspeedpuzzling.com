<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115143938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_import_pile_answer (last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ended_as VARCHAR(16) DEFAULT NULL, last_puzzle_id UUID DEFAULT NULL, puzzles_decided INT DEFAULT 0 NOT NULL, puzzles_skipped INT DEFAULT 0 NOT NULL, id UUID NOT NULL, pile VARCHAR(16) NOT NULL, choice VARCHAR(16) NOT NULL, revision VARCHAR(120) NOT NULL, puzzles_total INT NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, import_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8A46D7CDB6A263D9 ON data_import_pile_answer (import_id)');
        $this->addSql('CREATE INDEX IDX_8A46D7CDB6A263D9F9ED3E5335CE7A2D ON data_import_pile_answer (import_id, pile, finished_at)');
        $this->addSql('ALTER TABLE data_import_pile_answer ADD CONSTRAINT FK_8A46D7CDB6A263D9 FOREIGN KEY (import_id) REFERENCES data_import (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_pile_answer DROP CONSTRAINT FK_8A46D7CDB6A263D9');
        $this->addSql('DROP TABLE data_import_pile_answer');
    }
}
