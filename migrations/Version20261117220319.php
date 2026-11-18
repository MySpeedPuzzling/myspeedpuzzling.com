<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117220319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Divisions (shared-puzzles-and-divisions.md D1): competition_division, competition_round_division, division on round entries';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition_division (id UUID NOT NULL, name VARCHAR(60) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, short_name VARCHAR(12) DEFAULT NULL, position SMALLINT DEFAULT 0 NOT NULL, competition_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_21667C3B7B39D312 ON competition_division (competition_id)');
        $this->addSql('CREATE TABLE competition_round_division (id UUID NOT NULL, round_id UUID NOT NULL, division_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX competition_round_division_unique ON competition_round_division (round_id, division_id)');
        $this->addSql('CREATE INDEX IDX_9B818E64A6005CA0 ON competition_round_division (round_id)');
        $this->addSql('CREATE INDEX IDX_9B818E6441859289 ON competition_round_division (division_id)');
        $this->addSql('ALTER TABLE competition_division ADD CONSTRAINT FK_21667C3B7B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_round_division ADD CONSTRAINT FK_9B818E64A6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_round_division ADD CONSTRAINT FK_9B818E6441859289 FOREIGN KEY (division_id) REFERENCES competition_division (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_participant_round ADD division_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD CONSTRAINT FK_48D4E4341859289 FOREIGN KEY (division_id) REFERENCES competition_division (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_48D4E4341859289 ON competition_participant_round (division_id)');
        $this->addSql('ALTER TABLE competition_team ADD division_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_team ADD CONSTRAINT FK_CAA3380D41859289 FOREIGN KEY (division_id) REFERENCES competition_division (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_CAA3380D41859289 ON competition_team (division_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_division DROP CONSTRAINT FK_21667C3B7B39D312');
        $this->addSql('ALTER TABLE competition_round_division DROP CONSTRAINT FK_9B818E64A6005CA0');
        $this->addSql('ALTER TABLE competition_round_division DROP CONSTRAINT FK_9B818E6441859289');
        $this->addSql('DROP TABLE competition_division');
        $this->addSql('DROP TABLE competition_round_division');
        $this->addSql('ALTER TABLE competition_participant_round DROP CONSTRAINT FK_48D4E4341859289');
        $this->addSql('DROP INDEX IDX_48D4E4341859289');
        $this->addSql('ALTER TABLE competition_participant_round DROP division_id');
        $this->addSql('ALTER TABLE competition_team DROP CONSTRAINT FK_CAA3380D41859289');
        $this->addSql('DROP INDEX IDX_CAA3380D41859289');
        $this->addSql('ALTER TABLE competition_team DROP division_id');
    }
}
