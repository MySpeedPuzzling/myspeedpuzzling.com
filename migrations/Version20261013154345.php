<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013154345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE studio_invite (status VARCHAR(16) NOT NULL, answered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, on_air_from TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, on_air_to TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, invited_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, round_id UUID NOT NULL, participant_id UUID NOT NULL, invited_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_22C91B3AA6005CA0 ON studio_invite (round_id)');
        $this->addSql('CREATE INDEX IDX_22C91B3A9D1C3019 ON studio_invite (participant_id)');
        $this->addSql('CREATE INDEX IDX_22C91B3AA7B4A7E3 ON studio_invite (invited_by_id)');
        $this->addSql('ALTER TABLE studio_invite ADD CONSTRAINT FK_22C91B3AA6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE studio_invite ADD CONSTRAINT FK_22C91B3A9D1C3019 FOREIGN KEY (participant_id) REFERENCES competition_participant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE studio_invite ADD CONSTRAINT FK_22C91B3AA7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE studio_invite DROP CONSTRAINT FK_22C91B3AA6005CA0');
        $this->addSql('ALTER TABLE studio_invite DROP CONSTRAINT FK_22C91B3A9D1C3019');
        $this->addSql('ALTER TABLE studio_invite DROP CONSTRAINT FK_22C91B3AA7B4A7E3');
        $this->addSql('DROP TABLE studio_invite');
    }
}
