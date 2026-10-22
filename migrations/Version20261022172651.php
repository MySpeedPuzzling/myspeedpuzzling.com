<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261022172651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partner finder: partner_listing, partner_request, notification target, player e-mail switch, custom partial indexes';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE partner_listing (group_size SMALLINT NOT NULL, looking_for JSONB NOT NULL, needed SMALLINT DEFAULT NULL, co_member_ids JSONB DEFAULT \'[]\' NOT NULL, goal VARCHAR(255) DEFAULT NULL, strengths JSONB DEFAULT \'[]\' NOT NULL, custom_strengths JSONB DEFAULT \'[]\' NOT NULL, languages JSONB DEFAULT \'[]\' NOT NULL, note VARCHAR(200) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_reason VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, competition_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CEA2BC97B39D312 ON partner_listing (competition_id)');
        $this->addSql('CREATE INDEX IDX_CEA2BC999E6F5DF ON partner_listing (player_id)');
        $this->addSql('CREATE TABLE partner_request (replied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, note VARCHAR(200) DEFAULT NULL, id UUID NOT NULL, kind VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, listing_id UUID NOT NULL, from_player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FC7DEC82D4619D1A ON partner_request (listing_id)');
        $this->addSql('CREATE INDEX IDX_FC7DEC82897A6065 ON partner_request (from_player_id)');
        $this->addSql('CREATE INDEX partner_request_sender_created ON partner_request (from_player_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX partner_request_listing_sender_unique ON partner_request (listing_id, from_player_id)');
        $this->addSql('ALTER TABLE partner_listing ADD CONSTRAINT FK_CEA2BC97B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE partner_listing ADD CONSTRAINT FK_CEA2BC999E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE partner_request ADD CONSTRAINT FK_FC7DEC82D4619D1A FOREIGN KEY (listing_id) REFERENCES partner_listing (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE partner_request ADD CONSTRAINT FK_FC7DEC82897A6065 FOREIGN KEY (from_player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD target_partner_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA99EE7211 FOREIGN KEY (target_partner_request_id) REFERENCES partner_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA99EE7211 ON notification (target_partner_request_id)');
        $this->addSql('ALTER TABLE player ADD partner_finder_emails BOOLEAN DEFAULT true NOT NULL');

        // Custom indexes (docs/database-indexes.md): one open listing per player and event, and the open listings of an event
        $this->addSql('CREATE UNIQUE INDEX custom_partner_listing_one_open ON partner_listing (competition_id, player_id) WHERE closed_at IS NULL');
        $this->addSql('CREATE INDEX custom_partner_listing_open_by_event ON partner_listing (competition_id) WHERE closed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE partner_listing DROP CONSTRAINT FK_CEA2BC97B39D312');
        $this->addSql('ALTER TABLE partner_listing DROP CONSTRAINT FK_CEA2BC999E6F5DF');
        $this->addSql('ALTER TABLE partner_request DROP CONSTRAINT FK_FC7DEC82D4619D1A');
        $this->addSql('ALTER TABLE partner_request DROP CONSTRAINT FK_FC7DEC82897A6065');
        $this->addSql('DROP TABLE partner_listing');
        $this->addSql('DROP TABLE partner_request');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA99EE7211');
        $this->addSql('DROP INDEX IDX_BF5476CA99EE7211');
        $this->addSql('ALTER TABLE notification DROP target_partner_request_id');
        $this->addSql('ALTER TABLE player DROP partner_finder_emails');
    }
}
