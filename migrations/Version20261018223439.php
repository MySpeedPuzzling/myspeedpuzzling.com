<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018223439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE difficulty_prior_group (manufacturer_id UUID NOT NULL, pieces_count INT NOT NULL, index_count INT NOT NULL, sum_log_indices DOUBLE PRECISION NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (manufacturer_id, pieces_count))');
        $this->addSql('CREATE TABLE difficulty_prior_puzzle (puzzle_id UUID NOT NULL, index_count INT NOT NULL, sum_log_indices DOUBLE PRECISION NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (puzzle_id))');
        $this->addSql('CREATE TABLE prediction_model_fit (model_version SMALLINT NOT NULL, fitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, parameters JSONB NOT NULL, PRIMARY KEY (model_version))');
        $this->addSql('CREATE TABLE solving_time_prediction (source VARCHAR(255) NOT NULL, predictable BOOLEAN NOT NULL, method VARCHAR(255) DEFAULT NULL, predicted_seconds INT DEFAULT NULL, range50_low INT DEFAULT NULL, range50_high INT DEFAULT NULL, range80_low INT DEFAULT NULL, range80_high INT DEFAULT NULL, difficulty_used DOUBLE PRECISION DEFAULT NULL, difficulty_source VARCHAR(255) DEFAULT NULL, k_indices INT DEFAULT NULL, prior_log DOUBLE PRECISION DEFAULT NULL, window_bucket VARCHAR(40) DEFAULT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, predictor VARCHAR(255) NOT NULL, model_version SMALLINT NOT NULL, time_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B40D9D315EEADD3B ON solving_time_prediction (time_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B40D9D315EEADD3B1385BDDF8FBA32 ON solving_time_prediction (time_id, predictor, model_version)');
        $this->addSql('ALTER TABLE solving_time_prediction ADD CONSTRAINT FK_B40D9D315EEADD3B FOREIGN KEY (time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player_pace_history ADD spread DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD predicted_range80_low_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD predicted_range80_high_seconds INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE solving_time_prediction DROP CONSTRAINT FK_B40D9D315EEADD3B');
        $this->addSql('DROP TABLE difficulty_prior_group');
        $this->addSql('DROP TABLE difficulty_prior_puzzle');
        $this->addSql('DROP TABLE prediction_model_fit');
        $this->addSql('DROP TABLE solving_time_prediction');
        $this->addSql('ALTER TABLE player_pace_history DROP spread');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP predicted_range80_low_seconds');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP predicted_range80_high_seconds');
    }
}
