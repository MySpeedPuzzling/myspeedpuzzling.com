<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260331090121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add improvement ratio tables for data-driven time predictions, drop puzzle_pieces_count index';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE global_improvement_ratio (id UUID NOT NULL, pieces_count INT NOT NULL, from_attempt INT NOT NULL, gap_bucket VARCHAR(10) NOT NULL, median_ratio DOUBLE PRECISION NOT NULL, sample_size INT NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_338B5F13DD8EF0479EB2E1C9B4997D53 ON global_improvement_ratio (pieces_count, from_attempt, gap_bucket)');
        $this->addSql('CREATE TABLE player_improvement_ratio (id UUID NOT NULL, from_attempt INT NOT NULL, median_ratio DOUBLE PRECISION NOT NULL, sample_size INT NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6A90346599E6F5DF ON player_improvement_ratio (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6A90346599E6F5DF9EB2E1C9 ON player_improvement_ratio (player_id, from_attempt)');
        $this->addSql('ALTER TABLE player_improvement_ratio ADD CONSTRAINT FK_6A90346599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('DROP INDEX puzzle_pieces_count');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_improvement_ratio DROP CONSTRAINT FK_6A90346599E6F5DF');
        $this->addSql('DROP TABLE global_improvement_ratio');
        $this->addSql('DROP TABLE player_improvement_ratio');
        $this->addSql('CREATE INDEX puzzle_pieces_count ON puzzle (pieces_count)');
    }
}
