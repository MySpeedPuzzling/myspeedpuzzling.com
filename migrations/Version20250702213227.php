<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250702213227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition_round_puzzle (competition_round_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY(competition_round_id, puzzle_id))');
        $this->addSql('CREATE INDEX IDX_51841BE79771678F ON competition_round_puzzle (competition_round_id)');
        $this->addSql('CREATE INDEX IDX_51841BE7D9816812 ON competition_round_puzzle (puzzle_id)');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD CONSTRAINT FK_51841BE79771678F FOREIGN KEY (competition_round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD CONSTRAINT FK_51841BE7D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_round_puzzle DROP CONSTRAINT FK_51841BE79771678F');
        $this->addSql('ALTER TABLE competition_round_puzzle DROP CONSTRAINT FK_51841BE7D9816812');
        $this->addSql('DROP TABLE competition_round_puzzle');
    }
}
