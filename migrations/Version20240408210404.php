<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240408210404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE player_puzzle_collection (id UUID NOT NULL, player_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_E66A5EF099E6F5DF ON player_puzzle_collection (player_id)');
        $this->addSql('CREATE INDEX IDX_E66A5EF0D9816812 ON player_puzzle_collection (puzzle_id)');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.player_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.puzzle_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE player_puzzle_collection ADD CONSTRAINT FK_E66A5EF099E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player_puzzle_collection ADD CONSTRAINT FK_E66A5EF0D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE player_puzzle_collection DROP CONSTRAINT FK_E66A5EF099E6F5DF');
        $this->addSql('ALTER TABLE player_puzzle_collection DROP CONSTRAINT FK_E66A5EF0D9816812');
        $this->addSql('DROP TABLE player_puzzle_collection');
    }
}
