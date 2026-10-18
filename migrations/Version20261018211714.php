<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018211714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insights 3a: player_pace_history (as-of pace per player and piece count), pace_history_build marker, puzzle_difficulty.sum_log_indices';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pace_history_build (id INT NOT NULL, version INT NOT NULL, built_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE player_pace_history (id UUID NOT NULL, pieces_count INT NOT NULL, valid_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, valid_from_tracked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, baseline_seconds INT NOT NULL, qualifying_count INT NOT NULL, step DOUBLE PRECISION DEFAULT NULL, drift DOUBLE PRECISION DEFAULT NULL, inputs_checksum BIGINT NOT NULL, player_id UUID NOT NULL, time_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_540C6B8699E6F5DF ON player_pace_history (player_id)');
        $this->addSql('CREATE INDEX IDX_540C6B865EEADD3B ON player_pace_history (time_id)');
        $this->addSql('CREATE INDEX IDX_540C6B8699E6F5DFDD8EF0477BD7DFB6549A806E ON player_pace_history (player_id, pieces_count, valid_from, valid_from_tracked_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_540C6B8699E6F5DFDD8EF047EFA05AF0 ON player_pace_history (player_id, pieces_count, qualifying_count)');
        $this->addSql('ALTER TABLE player_pace_history ADD CONSTRAINT FK_540C6B8699E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player_pace_history ADD CONSTRAINT FK_540C6B865EEADD3B FOREIGN KEY (time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_difficulty ADD sum_log_indices DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_pace_history DROP CONSTRAINT FK_540C6B8699E6F5DF');
        $this->addSql('ALTER TABLE player_pace_history DROP CONSTRAINT FK_540C6B865EEADD3B');
        $this->addSql('DROP TABLE pace_history_build');
        $this->addSql('DROP TABLE player_pace_history');
        $this->addSql('ALTER TABLE puzzle_difficulty DROP sum_log_indices');
    }
}
