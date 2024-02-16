<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240216124858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_round ADD competition_id UUID NOT NULL');
        $this->addSql('COMMENT ON COLUMN competition_round.competition_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE competition_round ADD CONSTRAINT FK_3659D8E27B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_3659D8E27B39D312 ON competition_round (competition_id)');
        $this->addSql('ALTER TABLE puzzle DROP CONSTRAINT fk_22a6dfdf9771678f');
        $this->addSql('DROP INDEX idx_22a6dfdf9771678f');
        $this->addSql('ALTER TABLE puzzle DROP competition_round_id');
        $this->addSql('ALTER TABLE puzzle DROP missing_pieces');
        $this->addSql('ALTER TABLE puzzle DROP qualified');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD competition_round_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD missing_pieces INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD qualified BOOLEAN DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.competition_round_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT FK_FE83A93C9771678F FOREIGN KEY (competition_round_id) REFERENCES competition_round (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_FE83A93C9771678F ON puzzle_solving_time (competition_round_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE puzzle ADD competition_round_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle ADD missing_pieces INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle ADD qualified BOOLEAN DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN puzzle.competition_round_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE puzzle ADD CONSTRAINT fk_22a6dfdf9771678f FOREIGN KEY (competition_round_id) REFERENCES competition_round (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_22a6dfdf9771678f ON puzzle (competition_round_id)');
        $this->addSql('ALTER TABLE competition_round DROP CONSTRAINT FK_3659D8E27B39D312');
        $this->addSql('DROP INDEX IDX_3659D8E27B39D312');
        $this->addSql('ALTER TABLE competition_round DROP competition_id');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT FK_FE83A93C9771678F');
        $this->addSql('DROP INDEX IDX_FE83A93C9771678F');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP competition_round_id');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP missing_pieces');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP qualified');
    }
}
