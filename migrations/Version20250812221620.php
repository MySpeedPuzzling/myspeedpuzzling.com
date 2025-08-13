<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250812221620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create player_puzzle_collection_old table and copy data from player_puzzle_collection for collections rework';
    }

    public function up(Schema $schema): void
    {
        // Create new table with same structure
        $this->addSql('CREATE TABLE player_puzzle_collection_old (id UUID NOT NULL, player_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_player_puzzle_collection_old_player ON player_puzzle_collection_old (player_id)');
        $this->addSql('CREATE INDEX IDX_player_puzzle_collection_old_puzzle ON player_puzzle_collection_old (puzzle_id)');
        $this->addSql('ALTER TABLE player_puzzle_collection_old ADD CONSTRAINT FK_player_puzzle_collection_old_player FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player_puzzle_collection_old ADD CONSTRAINT FK_player_puzzle_collection_old_puzzle FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Copy all data from original table, but only insert rows that don't already exist (by id)
        $this->addSql('INSERT INTO player_puzzle_collection_old (id, player_id, puzzle_id)
                       SELECT ppc.id, ppc.player_id, ppc.puzzle_id
                       FROM player_puzzle_collection ppc
                       WHERE NOT EXISTS (
                           SELECT 1 FROM player_puzzle_collection_old old WHERE old.id = ppc.id
                       )');
    }

    public function down(Schema $schema): void
    {
    }
}
