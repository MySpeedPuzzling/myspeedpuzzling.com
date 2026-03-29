<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329233042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle Insights v2: update player_elo schema, add baseline_type, improvement_ceiling, rating snapshots';
    }

    public function up(Schema $schema): void
    {
        // player_rating_snapshot — new table for historical tracking
        $this->addSql('CREATE TABLE player_rating_snapshot (id UUID NOT NULL, pieces_count INT NOT NULL, snapshot_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, skill_score DOUBLE PRECISION DEFAULT NULL, skill_tier INT DEFAULT NULL, skill_percentile DOUBLE PRECISION DEFAULT NULL, elo_rating DOUBLE PRECISION DEFAULT NULL, elo_rank INT DEFAULT NULL, baseline_seconds INT DEFAULT NULL, baseline_type VARCHAR(255) DEFAULT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AB6A238999E6F5DF ON player_rating_snapshot (player_id)');
        $this->addSql('CREATE INDEX IDX_AB6A238999E6F5DFB8CEA207 ON player_rating_snapshot (player_id, snapshot_date)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AB6A238999E6F5DFDD8EF047B8CEA207 ON player_rating_snapshot (player_id, pieces_count, snapshot_date)');
        $this->addSql('ALTER TABLE player_rating_snapshot ADD CONSTRAINT FK_AB6A238999E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');

        // player_baseline — add baseline_type column
        $this->addSql("ALTER TABLE player_baseline ADD baseline_type VARCHAR(255) DEFAULT 'direct' NOT NULL");

        // player_elo — v2 schema: drop period/matches/lastSolve, convert rating to float
        // Wipe all data first since the rating scale completely changes
        $this->addSql('DELETE FROM player_elo');
        $this->addSql('DROP INDEX idx_d839c998dd8ef047c5b81ecedb0e793a');
        $this->addSql('DROP INDEX uniq_d839c99899e6f5dfdd8ef047c5b81ece');
        $this->addSql('ALTER TABLE player_elo DROP period');
        $this->addSql('ALTER TABLE player_elo DROP matches_count');
        $this->addSql('ALTER TABLE player_elo DROP last_solve_at');
        $this->addSql('ALTER TABLE player_elo ALTER elo_rating TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE player_elo ALTER elo_rating DROP DEFAULT');
        $this->addSql('CREATE INDEX IDX_D839C998DD8EF047DB0E793A ON player_elo (pieces_count, elo_rating)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D839C99899E6F5DFDD8EF047 ON player_elo (player_id, pieces_count)');

        // puzzle_difficulty — add improvement_ceiling_score
        $this->addSql('ALTER TABLE puzzle_difficulty ADD improvement_ceiling_score DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_rating_snapshot DROP CONSTRAINT FK_AB6A238999E6F5DF');
        $this->addSql('DROP TABLE player_rating_snapshot');

        $this->addSql('ALTER TABLE player_baseline DROP baseline_type');

        $this->addSql('DROP INDEX IDX_D839C998DD8EF047DB0E793A');
        $this->addSql('DROP INDEX UNIQ_D839C99899E6F5DFDD8EF047');
        $this->addSql("ALTER TABLE player_elo ADD period VARCHAR(255) NOT NULL DEFAULT 'all-time'");
        $this->addSql('ALTER TABLE player_elo ADD matches_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE player_elo ADD last_solve_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE player_elo ALTER elo_rating TYPE INT');
        $this->addSql('ALTER TABLE player_elo ALTER elo_rating SET DEFAULT 1000');
        $this->addSql('CREATE INDEX idx_d839c998dd8ef047c5b81ecedb0e793a ON player_elo (pieces_count, period, elo_rating)');
        $this->addSql('CREATE UNIQUE INDEX uniq_d839c99899e6f5dfdd8ef047c5b81ece ON player_elo (player_id, pieces_count, period)');

        $this->addSql('ALTER TABLE puzzle_difficulty DROP improvement_ceiling_score');
    }
}
