<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117220629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Participant linking L2a: competition.participant_claims (claim policy) and participant_link_request';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE participant_link_request (status VARCHAR(20) NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, kind VARCHAR(20) NOT NULL, player_name VARCHAR(255) NOT NULL, player_code VARCHAR(255) NOT NULL, releases_own_registration BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, decided_by_id UUID DEFAULT NULL, competition_id UUID NOT NULL, participant_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DBD991387B39D3127B00651C ON participant_link_request (competition_id, status)');
        $this->addSql('CREATE INDEX IDX_DBD991389D1C30197B00651C ON participant_link_request (participant_id, status)');
        $this->addSql('CREATE INDEX IDX_DBD9913899E6F5DF7B39D312 ON participant_link_request (player_id, competition_id)');
        $this->addSql('CREATE INDEX IDX_DBD99138E26B496B ON participant_link_request (decided_by_id)');
        $this->addSql('CREATE INDEX IDX_DBD991387B39D312 ON participant_link_request (competition_id)');
        $this->addSql('CREATE INDEX IDX_DBD991389D1C3019 ON participant_link_request (participant_id)');
        $this->addSql('CREATE INDEX IDX_DBD9913899E6F5DF ON participant_link_request (player_id)');
        $this->addSql('ALTER TABLE participant_link_request ADD CONSTRAINT FK_DBD99138E26B496B FOREIGN KEY (decided_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE participant_link_request ADD CONSTRAINT FK_DBD991387B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE participant_link_request ADD CONSTRAINT FK_DBD991389D1C3019 FOREIGN KEY (participant_id) REFERENCES competition_participant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE participant_link_request ADD CONSTRAINT FK_DBD9913899E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition ADD participant_claims VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE participant_link_request DROP CONSTRAINT FK_DBD99138E26B496B');
        $this->addSql('ALTER TABLE participant_link_request DROP CONSTRAINT FK_DBD991387B39D312');
        $this->addSql('ALTER TABLE participant_link_request DROP CONSTRAINT FK_DBD991389D1C3019');
        $this->addSql('ALTER TABLE participant_link_request DROP CONSTRAINT FK_DBD9913899E6F5DF');
        $this->addSql('DROP TABLE participant_link_request');
        $this->addSql('ALTER TABLE competition DROP participant_claims');
    }
}
