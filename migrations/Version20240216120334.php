<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240216120334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition (id UUID NOT NULL, name VARCHAR(255) NOT NULL, location VARCHAR(255) NOT NULL, date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN competition.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN competition.date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE competition_round (id UUID NOT NULL, name VARCHAR(255) NOT NULL, minutes_limit INT NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN competition_round.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN competition_round.starts_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE puzzle ADD competition_round_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle ADD missing_pieces INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle ADD qualified BOOLEAN DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN puzzle.competition_round_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE puzzle ADD CONSTRAINT FK_22A6DFDF9771678F FOREIGN KEY (competition_round_id) REFERENCES competition_round (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_22A6DFDF9771678F ON puzzle (competition_round_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE puzzle DROP CONSTRAINT FK_22A6DFDF9771678F');
        $this->addSql('DROP TABLE competition');
        $this->addSql('DROP TABLE competition_round');
        $this->addSql('DROP INDEX IDX_22A6DFDF9771678F');
        $this->addSql('ALTER TABLE puzzle DROP competition_round_id');
        $this->addSql('ALTER TABLE puzzle DROP missing_pieces');
        $this->addSql('ALTER TABLE puzzle DROP qualified');
    }
}
