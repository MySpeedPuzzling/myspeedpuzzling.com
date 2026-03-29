<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324232533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add puzzle intelligence tables: player_baseline, puzzle_difficulty, player_skill, player_skill_history, player_elo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE player_baseline (id UUID NOT NULL, pieces_count INT NOT NULL, baseline_seconds INT NOT NULL, qualifying_solves_count INT NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F0CE92A699E6F5DF ON player_baseline (player_id)');
        $this->addSql('CREATE INDEX IDX_F0CE92A6DD8EF047 ON player_baseline (pieces_count)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F0CE92A699E6F5DFDD8EF047 ON player_baseline (player_id, pieces_count)');
        $this->addSql('ALTER TABLE player_baseline ADD CONSTRAINT FK_F0CE92A699E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE puzzle_difficulty (difficulty_score DOUBLE PRECISION DEFAULT NULL, difficulty_tier INT DEFAULT NULL, confidence VARCHAR(255) NOT NULL, sample_size INT DEFAULT 0 NOT NULL, memorability_score DOUBLE PRECISION DEFAULT NULL, skill_sensitivity_score DOUBLE PRECISION DEFAULT NULL, predictability_score DOUBLE PRECISION DEFAULT NULL, box_dependence_score DOUBLE PRECISION DEFAULT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (puzzle_id))');
        $this->addSql('CREATE INDEX IDX_C670C963F3C90378 ON puzzle_difficulty (difficulty_tier)');
        $this->addSql('ALTER TABLE puzzle_difficulty ADD CONSTRAINT FK_C670C963D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE player_skill (id UUID NOT NULL, pieces_count INT NOT NULL, skill_score DOUBLE PRECISION NOT NULL, skill_tier INT NOT NULL, skill_percentile DOUBLE PRECISION NOT NULL, confidence VARCHAR(255) NOT NULL, qualifying_puzzles_count INT NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E14F9F3199E6F5DF ON player_skill (player_id)');
        $this->addSql('CREATE INDEX IDX_E14F9F31DD8EF047 ON player_skill (pieces_count)');
        $this->addSql('CREATE INDEX IDX_E14F9F3163DF0B38 ON player_skill (skill_tier)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E14F9F3199E6F5DFDD8EF047 ON player_skill (player_id, pieces_count)');
        $this->addSql('ALTER TABLE player_skill ADD CONSTRAINT FK_E14F9F3199E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE player_skill_history (id UUID NOT NULL, pieces_count INT NOT NULL, month TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, baseline_seconds INT NOT NULL, skill_tier INT DEFAULT NULL, skill_percentile DOUBLE PRECISION DEFAULT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E8AF92D699E6F5DF ON player_skill_history (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E8AF92D699E6F5DFDD8EF0478EB61006 ON player_skill_history (player_id, pieces_count, month)');
        $this->addSql('ALTER TABLE player_skill_history ADD CONSTRAINT FK_E8AF92D699E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE player_elo (id UUID NOT NULL, pieces_count INT NOT NULL, period VARCHAR(255) NOT NULL, elo_rating INT DEFAULT 1000 NOT NULL, matches_count INT DEFAULT 0 NOT NULL, last_solve_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D839C99899E6F5DF ON player_elo (player_id)');
        $this->addSql('CREATE INDEX IDX_D839C998DD8EF047C5B81ECEDB0E793A ON player_elo (pieces_count, period, elo_rating)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D839C99899E6F5DFDD8EF047C5B81ECE ON player_elo (player_id, pieces_count, period)');
        $this->addSql('ALTER TABLE player_elo ADD CONSTRAINT FK_D839C99899E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_baseline DROP CONSTRAINT FK_F0CE92A699E6F5DF');
        $this->addSql('ALTER TABLE player_elo DROP CONSTRAINT FK_D839C99899E6F5DF');
        $this->addSql('ALTER TABLE player_skill DROP CONSTRAINT FK_E14F9F3199E6F5DF');
        $this->addSql('ALTER TABLE player_skill_history DROP CONSTRAINT FK_E8AF92D699E6F5DF');
        $this->addSql('ALTER TABLE puzzle_difficulty DROP CONSTRAINT FK_C670C963D9816812');
        $this->addSql('DROP TABLE player_baseline');
        $this->addSql('DROP TABLE player_elo');
        $this->addSql('DROP TABLE player_skill');
        $this->addSql('DROP TABLE player_skill_history');
        $this->addSql('DROP TABLE puzzle_difficulty');
    }
}
