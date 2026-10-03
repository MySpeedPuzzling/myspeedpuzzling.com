<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003170510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE community_player_stats (solved_total INT NOT NULL, pieces_total INT NOT NULL, solves7d INT NOT NULL, pieces7d INT NOT NULL, solves30d INT NOT NULL, solves_prev30d INT NOT NULL, solves_this_month INT NOT NULL, pieces_this_month INT NOT NULL, solves_last_month INT NOT NULL, pieces_last_month INT NOT NULL, best500_seconds INT DEFAULT NULL, best1000_seconds INT DEFAULT NULL, first_solved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_solved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, monthly_solves JSONB NOT NULL, favorites_count INT NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (player_id))');
        $this->addSql('CREATE TABLE community_scope_stats (scope VARCHAR(10) NOT NULL, registered_players INT NOT NULL, active30d INT NOT NULL, solves30d INT NOT NULL, solves_prev30d INT NOT NULL, active_this_month INT NOT NULL, pieces_this_month INT NOT NULL, active_last_month INT NOT NULL, pieces_last_month INT NOT NULL, median_best500_seconds INT DEFAULT NULL, puzzlers_with500 INT NOT NULL, monthly_solves JSONB NOT NULL, new_faces14d INT NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (scope))');
        $this->addSql('CREATE TABLE player_moment (id UUID NOT NULL, type VARCHAR(30) NOT NULL, dedupe_key VARCHAR(80) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, pieces_count INT DEFAULT NULL, value INT DEFAULT NULL, previous_value INT DEFAULT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, solving_time_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AA8C1E7C99E6F5DF ON player_moment (player_id)');
        $this->addSql('CREATE INDEX IDX_AA8C1E7CD6B05C60 ON player_moment (solving_time_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AA8C1E7C99E6F5DF7BCCC1E9 ON player_moment (player_id, dedupe_key)');
        $this->addSql('ALTER TABLE community_player_stats ADD CONSTRAINT FK_B724A47399E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player_moment ADD CONSTRAINT FK_AA8C1E7C99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player_moment ADD CONSTRAINT FK_AA8C1E7CD6B05C60 FOREIGN KEY (solving_time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE community_player_stats DROP CONSTRAINT FK_B724A47399E6F5DF');
        $this->addSql('ALTER TABLE player_moment DROP CONSTRAINT FK_AA8C1E7C99E6F5DF');
        $this->addSql('ALTER TABLE player_moment DROP CONSTRAINT FK_AA8C1E7CD6B05C60');
        $this->addSql('DROP TABLE community_player_stats');
        $this->addSql('DROP TABLE community_scope_stats');
        $this->addSql('DROP TABLE player_moment');
    }
}
