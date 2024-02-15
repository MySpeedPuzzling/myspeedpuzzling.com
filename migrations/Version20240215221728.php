<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240215221728 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tag (id UUID NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN tag.id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE tag_puzzle (tag_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY(tag_id, puzzle_id))');
        $this->addSql('CREATE INDEX IDX_CBE796F3BAD26311 ON tag_puzzle (tag_id)');
        $this->addSql('CREATE INDEX IDX_CBE796F3D9816812 ON tag_puzzle (puzzle_id)');
        $this->addSql('COMMENT ON COLUMN tag_puzzle.tag_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN tag_puzzle.puzzle_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE tag_puzzle ADD CONSTRAINT FK_CBE796F3BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE tag_puzzle ADD CONSTRAINT FK_CBE796F3D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE tag_puzzle DROP CONSTRAINT FK_CBE796F3BAD26311');
        $this->addSql('ALTER TABLE tag_puzzle DROP CONSTRAINT FK_CBE796F3D9816812');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE tag_puzzle');
    }
}
