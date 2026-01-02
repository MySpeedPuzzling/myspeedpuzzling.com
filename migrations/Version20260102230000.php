<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260102230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes for query optimization';
    }

    public function up(Schema $schema): void
    {
        // Composite index for player statistics and ranking queries
        $this->addSql('CREATE INDEX idx_pst_player_puzzle_type ON puzzle_solving_time (player_id, puzzle_id, puzzling_type)');

        // Index for date-based monthly queries (used after EXTRACT->date range optimization)
        $this->addSql('CREATE INDEX idx_pst_tracked_at_type ON puzzle_solving_time (tracked_at, puzzling_type)');

        // Partial index for fastest players/groups/pairs queries
        $this->addSql('CREATE INDEX idx_pst_type_time_valid ON puzzle_solving_time (puzzling_type, seconds_to_solve) WHERE seconds_to_solve IS NOT NULL AND suspicious = false');

        // GIN index for JSONB containment on team column (custom_ prefix = Doctrine won't manage it)
        $this->addSql('CREATE INDEX custom_pst_team_puzzlers_gin ON puzzle_solving_time USING GIN ((team::jsonb->\'puzzlers\') jsonb_path_ops) WHERE team IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_pst_player_puzzle_type');
        $this->addSql('DROP INDEX IF EXISTS idx_pst_tracked_at_type');
        $this->addSql('DROP INDEX IF EXISTS idx_pst_type_time_valid');
        $this->addSql('DROP INDEX IF EXISTS custom_pst_team_puzzlers_gin');
    }
}
