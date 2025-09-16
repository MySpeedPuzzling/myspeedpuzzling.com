<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250903194921 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Collections feature - backup and drop player_puzzle_collection table';
    }

    public function up(Schema $schema): void
    {
        // Create backup table with all data
        $this->addSql('CREATE TABLE tmp_collection AS SELECT * FROM player_puzzle_collection');

        // Drop the original table
        $this->addSql('DROP TABLE player_puzzle_collection');
    }

    public function down(Schema $schema): void
    {
        // Restore the table structure
        $this->addSql('CREATE TABLE player_puzzle_collection (
            player_id UUID NOT NULL,
            puzzle_id UUID NOT NULL,
            added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(player_id, puzzle_id)
        )');
        $this->addSql('CREATE INDEX IDX_83BFADB599E6F5DF ON player_puzzle_collection (player_id)');
        $this->addSql('CREATE INDEX IDX_83BFADB5D9816812 ON player_puzzle_collection (puzzle_id)');
        $this->addSql('ALTER TABLE player_puzzle_collection 
            ADD CONSTRAINT FK_83BFADB599E6F5DF FOREIGN KEY (player_id) 
            REFERENCES player (player_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player_puzzle_collection 
            ADD CONSTRAINT FK_83BFADB5D9816812 FOREIGN KEY (puzzle_id) 
            REFERENCES puzzle (puzzle_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Restore data from backup
        $this->addSql('INSERT INTO player_puzzle_collection SELECT * FROM tmp_collection');

        // Drop backup table
        $this->addSql('DROP TABLE tmp_collection');
    }
}
