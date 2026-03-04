<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304133125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition management: add approval workflow, maintainers, round puzzles entity, puzzle hide_until';
    }

    public function up(Schema $schema): void
    {
        // Competition maintainers join table
        $this->addSql('CREATE TABLE competition_maintainer (competition_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (competition_id, player_id))');
        $this->addSql('CREATE INDEX IDX_ADB6DE3A7B39D312 ON competition_maintainer (competition_id)');
        $this->addSql('CREATE INDEX IDX_ADB6DE3A99E6F5DF ON competition_maintainer (player_id)');
        $this->addSql('ALTER TABLE competition_maintainer ADD CONSTRAINT FK_ADB6DE3A7B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE competition_maintainer ADD CONSTRAINT FK_ADB6DE3A99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE');

        // Competition approval fields
        $this->addSql('ALTER TABLE competition ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD added_by_player_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD approved_by_player_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD CONSTRAINT FK_B50A2CB13EDBBB76 FOREIGN KEY (added_by_player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE competition ADD CONSTRAINT FK_B50A2CB143132E94 FOREIGN KEY (approved_by_player_id) REFERENCES player (id)');
        $this->addSql('CREATE INDEX IDX_B50A2CB13EDBBB76 ON competition (added_by_player_id)');
        $this->addSql('CREATE INDEX IDX_B50A2CB143132E94 ON competition (approved_by_player_id)');

        // Set all existing competitions as approved
        $this->addSql('UPDATE competition SET approved_at = NOW() WHERE approved_at IS NULL');

        // Convert competition_round_puzzle from ManyToMany join table to proper entity
        // First, save existing data
        $this->addSql('CREATE TEMP TABLE _crp_backup AS SELECT competition_round_id, puzzle_id FROM competition_round_puzzle');

        // Drop old table constraints and table
        $this->addSql('ALTER TABLE competition_round_puzzle DROP CONSTRAINT IF EXISTS fk_51841be7d9816812');
        $this->addSql('ALTER TABLE competition_round_puzzle DROP CONSTRAINT IF EXISTS fk_51841be79771678f');
        $this->addSql('DROP TABLE competition_round_puzzle');

        // Create new entity table
        $this->addSql('CREATE TABLE competition_round_puzzle (id UUID NOT NULL, round_id UUID NOT NULL, puzzle_id UUID NOT NULL, hide_until_round_starts BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_51841BE7A6005CA0 ON competition_round_puzzle (round_id)');
        $this->addSql('CREATE INDEX IDX_51841BE7D9816812 ON competition_round_puzzle (puzzle_id)');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD CONSTRAINT FK_51841BE7A6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD CONSTRAINT FK_51841BE7D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE');

        // Restore data with generated UUIDs
        $this->addSql('INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts) SELECT gen_random_uuid(), competition_round_id, puzzle_id, false FROM _crp_backup');
        $this->addSql('DROP TABLE _crp_backup');

        // Puzzle hide_until field
        $this->addSql('ALTER TABLE puzzle ADD hide_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_maintainer DROP CONSTRAINT FK_ADB6DE3A7B39D312');
        $this->addSql('ALTER TABLE competition_maintainer DROP CONSTRAINT FK_ADB6DE3A99E6F5DF');
        $this->addSql('DROP TABLE competition_maintainer');

        $this->addSql('ALTER TABLE competition DROP CONSTRAINT FK_B50A2CB13EDBBB76');
        $this->addSql('ALTER TABLE competition DROP CONSTRAINT FK_B50A2CB143132E94');
        $this->addSql('DROP INDEX IDX_B50A2CB13EDBBB76');
        $this->addSql('DROP INDEX IDX_B50A2CB143132E94');
        $this->addSql('ALTER TABLE competition DROP approved_at');
        $this->addSql('ALTER TABLE competition DROP created_at');
        $this->addSql('ALTER TABLE competition DROP added_by_player_id');
        $this->addSql('ALTER TABLE competition DROP approved_by_player_id');

        // Restore old ManyToMany join table
        $this->addSql('CREATE TEMP TABLE _crp_backup AS SELECT round_id, puzzle_id FROM competition_round_puzzle');
        $this->addSql('ALTER TABLE competition_round_puzzle DROP CONSTRAINT FK_51841BE7A6005CA0');
        $this->addSql('ALTER TABLE competition_round_puzzle DROP CONSTRAINT FK_51841BE7D9816812');
        $this->addSql('DROP TABLE competition_round_puzzle');

        $this->addSql('CREATE TABLE competition_round_puzzle (competition_round_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (competition_round_id, puzzle_id))');
        $this->addSql('CREATE INDEX idx_51841be79771678f ON competition_round_puzzle (competition_round_id)');
        $this->addSql('CREATE INDEX idx_51841be7d9816812 ON competition_round_puzzle (puzzle_id)');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD CONSTRAINT fk_51841be79771678f FOREIGN KEY (competition_round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD CONSTRAINT fk_51841be7d9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('INSERT INTO competition_round_puzzle (competition_round_id, puzzle_id) SELECT round_id, puzzle_id FROM _crp_backup');
        $this->addSql('DROP TABLE _crp_backup');

        $this->addSql('ALTER TABLE puzzle DROP hide_until');
    }
}
