<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250702211311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition_participant (connected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, remote_id VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, country VARCHAR(255) NOT NULL, player_id UUID DEFAULT NULL, competition_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FF3EC46F99E6F5DF ON competition_participant (player_id)');
        $this->addSql('CREATE INDEX IDX_FF3EC46F7B39D312 ON competition_participant (competition_id)');
        $this->addSql('CREATE TABLE competition_participant_round (id UUID NOT NULL, participant_id UUID NOT NULL, round_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_48D4E439D1C3019 ON competition_participant_round (participant_id)');
        $this->addSql('CREATE INDEX IDX_48D4E43A6005CA0 ON competition_participant_round (round_id)');
        $this->addSql('ALTER TABLE competition_participant ADD CONSTRAINT FK_FF3EC46F99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE competition_participant ADD CONSTRAINT FK_FF3EC46F7B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE competition_participant_round ADD CONSTRAINT FK_48D4E439D1C3019 FOREIGN KEY (participant_id) REFERENCES competition_participant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE competition_participant_round ADD CONSTRAINT FK_48D4E43A6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_participant DROP CONSTRAINT FK_FF3EC46F99E6F5DF');
        $this->addSql('ALTER TABLE competition_participant DROP CONSTRAINT FK_FF3EC46F7B39D312');
        $this->addSql('ALTER TABLE competition_participant_round DROP CONSTRAINT FK_48D4E439D1C3019');
        $this->addSql('ALTER TABLE competition_participant_round DROP CONSTRAINT FK_48D4E43A6005CA0');
        $this->addSql('DROP TABLE competition_participant');
        $this->addSql('DROP TABLE competition_participant_round');
    }
}
