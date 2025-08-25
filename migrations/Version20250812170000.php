<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add collection folders functionality with lending support
 */
final class Version20250812170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add collection folders functionality with lending support';
    }

    public function up(Schema $schema): void
    {
        // Create collection_folder table
        $this->addSql('CREATE TABLE collection_folder (
            id UUID NOT NULL,
            player_id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            is_system BOOLEAN DEFAULT false NOT NULL,
            color VARCHAR(7) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            system_key VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        
        $this->addSql('CREATE INDEX IDX_collection_folder_player_id ON collection_folder (player_id)');
        $this->addSql('ALTER TABLE collection_folder ADD CONSTRAINT FK_collection_folder_player_id FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Add new columns to player_puzzle_collection table
        $this->addSql('ALTER TABLE player_puzzle_collection ADD folder_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE player_puzzle_collection ADD added_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE player_puzzle_collection ADD notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE player_puzzle_collection ADD lent_to_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE player_puzzle_collection ADD lent_at DATE DEFAULT NULL');

        // Convert timestamp column to immutable
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.added_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.lent_at IS \'(DC2Type:date_immutable)\'');

        // Add foreign key constraints
        $this->addSql('CREATE INDEX IDX_player_puzzle_collection_folder_id ON player_puzzle_collection (folder_id)');
        $this->addSql('CREATE INDEX IDX_player_puzzle_collection_lent_to_id ON player_puzzle_collection (lent_to_id)');
        
        $this->addSql('ALTER TABLE player_puzzle_collection ADD CONSTRAINT FK_player_puzzle_collection_folder_id FOREIGN KEY (folder_id) REFERENCES collection_folder (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player_puzzle_collection ADD CONSTRAINT FK_player_puzzle_collection_lent_to_id FOREIGN KEY (lent_to_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Remove foreign key constraints
        $this->addSql('ALTER TABLE player_puzzle_collection DROP CONSTRAINT FK_player_puzzle_collection_folder_id');
        $this->addSql('ALTER TABLE player_puzzle_collection DROP CONSTRAINT FK_player_puzzle_collection_lent_to_id');
        
        // Drop indexes
        $this->addSql('DROP INDEX IDX_player_puzzle_collection_folder_id');
        $this->addSql('DROP INDEX IDX_player_puzzle_collection_lent_to_id');
        
        // Remove columns from player_puzzle_collection
        $this->addSql('ALTER TABLE player_puzzle_collection DROP folder_id');
        $this->addSql('ALTER TABLE player_puzzle_collection DROP added_at');
        $this->addSql('ALTER TABLE player_puzzle_collection DROP notes');
        $this->addSql('ALTER TABLE player_puzzle_collection DROP lent_to_id');
        $this->addSql('ALTER TABLE player_puzzle_collection DROP lent_at');

        // Drop collection_folder table
        $this->addSql('ALTER TABLE collection_folder DROP CONSTRAINT FK_collection_folder_player_id');
        $this->addSql('DROP TABLE collection_folder');
    }
}