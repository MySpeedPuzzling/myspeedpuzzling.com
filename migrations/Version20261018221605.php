<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018221605 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insights batch additions: rating entries, puzzle rating times, coordination constants, profile cards, generation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE group_coordination_constant (group_size SMALLINT NOT NULL, first_attempt BOOLEAN NOT NULL, coordination DOUBLE PRECISION NOT NULL, sample_size INT NOT NULL, fitted BOOLEAN NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (group_size, first_attempt))');
        $this->addSql('CREATE TABLE insights_generation (id SMALLINT NOT NULL, generation BIGINT NOT NULL, advanced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE player_insights_profile (speed_score DOUBLE PRECISION DEFAULT NULL, speed_sample_size INT DEFAULT 0 NOT NULL, speed_percentile DOUBLE PRECISION DEFAULT NULL, improvement_score DOUBLE PRECISION DEFAULT NULL, improvement_sample_size INT DEFAULT 0 NOT NULL, improvement_percentile DOUBLE PRECISION DEFAULT NULL, consistency_score DOUBLE PRECISION DEFAULT NULL, consistency_sample_size INT DEFAULT 0 NOT NULL, consistency_percentile DOUBLE PRECISION DEFAULT NULL, learning_score DOUBLE PRECISION DEFAULT NULL, learning_sample_size INT DEFAULT 0 NOT NULL, learning_percentile DOUBLE PRECISION DEFAULT NULL, hard_puzzles_score DOUBLE PRECISION DEFAULT NULL, hard_puzzles_sample_size INT DEFAULT 0 NOT NULL, hard_puzzles_percentile DOUBLE PRECISION DEFAULT NULL, big_puzzles_score DOUBLE PRECISION DEFAULT NULL, big_puzzles_sample_size INT DEFAULT 0 NOT NULL, big_puzzles_percentile DOUBLE PRECISION DEFAULT NULL, events_score DOUBLE PRECISION DEFAULT NULL, events_sample_size INT DEFAULT 0 NOT NULL, events_percentile DOUBLE PRECISION DEFAULT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (player_id))');
        $this->addSql('CREATE TABLE player_rating_entry (pieces_count INT NOT NULL, portfolio VARCHAR(16) NOT NULL, seconds INT NOT NULL, solved_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, percentile DOUBLE PRECISION NOT NULL, difficulty_weight DOUBLE PRECISION NOT NULL, decay DOUBLE PRECISION NOT NULL, points DOUBLE PRECISION NOT NULL, in_top BOOLEAN NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (player_id, pieces_count, puzzle_id, portfolio))');
        $this->addSql('CREATE INDEX IDX_360AC26E99E6F5DF ON player_rating_entry (player_id)');
        $this->addSql('CREATE INDEX IDX_360AC26ED9816812 ON player_rating_entry (puzzle_id)');
        // Added by hand: the daily decay roll updates ~245k rows; free space per page keeps those updates HOT (no index
        // writes) - measured on the production copy: 206 -> ~100 MB of WAL for the roll (docs/features/insights/computation.md)
        $this->addSql('ALTER TABLE player_rating_entry SET (fillfactor = 70)');
        $this->addSql('CREATE TABLE puzzle_rating_times (pieces_count INT NOT NULL, solver_count INT NOT NULL, qualifies BOOLEAN NOT NULL, difficulty_weight DOUBLE PRECISION DEFAULT NULL, first_attempt_times JSONB NOT NULL, best_times JSONB NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (puzzle_id))');
        $this->addSql('ALTER TABLE player_insights_profile ADD CONSTRAINT FK_491FED3A99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player_rating_entry ADD CONSTRAINT FK_360AC26E99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player_rating_entry ADD CONSTRAINT FK_360AC26ED9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_rating_times ADD CONSTRAINT FK_28424D31D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_insights_profile DROP CONSTRAINT FK_491FED3A99E6F5DF');
        $this->addSql('ALTER TABLE player_rating_entry DROP CONSTRAINT FK_360AC26E99E6F5DF');
        $this->addSql('ALTER TABLE player_rating_entry DROP CONSTRAINT FK_360AC26ED9816812');
        $this->addSql('ALTER TABLE puzzle_rating_times DROP CONSTRAINT FK_28424D31D9816812');
        $this->addSql('DROP TABLE group_coordination_constant');
        $this->addSql('DROP TABLE insights_generation');
        $this->addSql('DROP TABLE player_insights_profile');
        $this->addSql('DROP TABLE player_rating_entry');
        $this->addSql('DROP TABLE puzzle_rating_times');
    }
}
