<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008214817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Organizations and drafts: organization + team, event URL redirects, organization/draft/eligibility on events and series, schedule on series, organization follows';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE event_url_redirect (id UUID NOT NULL, series_slug VARCHAR(255) DEFAULT \'\' NOT NULL, competition_slug VARCHAR(255) DEFAULT \'\' NOT NULL, round_slug VARCHAR(255) DEFAULT \'\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID DEFAULT NULL, series_id UUID DEFAULT NULL, competition_id UUID DEFAULT NULL, round_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4C7FFA5132C8A3DE ON event_url_redirect (organization_id)');
        $this->addSql('CREATE INDEX IDX_4C7FFA515278319C ON event_url_redirect (series_id)');
        $this->addSql('CREATE INDEX IDX_4C7FFA517B39D312 ON event_url_redirect (competition_id)');
        $this->addSql('CREATE INDEX IDX_4C7FFA51A6005CA0 ON event_url_redirect (round_id)');
        $this->addSql('CREATE UNIQUE INDEX event_url_redirect_path_unique ON event_url_redirect (series_slug, competition_slug, round_slug)');
        $this->addSql('CREATE TABLE organization (social_links JSONB DEFAULT \'[]\' NOT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, short_name VARCHAR(255) DEFAULT NULL, logo VARCHAR(255) DEFAULT NULL, about TEXT DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, country_code VARCHAR(255) DEFAULT NULL, region VARCHAR(255) DEFAULT NULL, kind VARCHAR(255) DEFAULT NULL, is_draft BOOLEAN DEFAULT false NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rejected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, added_by_player_id UUID DEFAULT NULL, approved_by_player_id UUID DEFAULT NULL, rejected_by_player_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1EE637C989D9B62 ON organization (slug)');
        $this->addSql('CREATE INDEX IDX_C1EE637C3EDBBB76 ON organization (added_by_player_id)');
        $this->addSql('CREATE INDEX IDX_C1EE637C43132E94 ON organization (approved_by_player_id)');
        $this->addSql('CREATE INDEX IDX_C1EE637CBC7FE91 ON organization (rejected_by_player_id)');
        $this->addSql('CREATE TABLE organization_maintainer (organization_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (organization_id, player_id))');
        $this->addSql('CREATE INDEX IDX_E7FAACED32C8A3DE ON organization_maintainer (organization_id)');
        $this->addSql('CREATE INDEX IDX_E7FAACED99E6F5DF ON organization_maintainer (player_id)');
        $this->addSql('ALTER TABLE event_url_redirect ADD CONSTRAINT FK_4C7FFA5132C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_url_redirect ADD CONSTRAINT FK_4C7FFA515278319C FOREIGN KEY (series_id) REFERENCES competition_series (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_url_redirect ADD CONSTRAINT FK_4C7FFA517B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_url_redirect ADD CONSTRAINT FK_4C7FFA51A6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT FK_C1EE637C3EDBBB76 FOREIGN KEY (added_by_player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT FK_C1EE637C43132E94 FOREIGN KEY (approved_by_player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT FK_C1EE637CBC7FE91 FOREIGN KEY (rejected_by_player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE organization_maintainer ADD CONSTRAINT FK_E7FAACED32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE organization_maintainer ADD CONSTRAINT FK_E7FAACED99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE competition ADD is_draft BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition ADD eligibility VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD organization_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD CONSTRAINT FK_B50A2CB132C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_B50A2CB132C8A3DE ON competition (organization_id)');
        $this->addSql('ALTER TABLE competition_series ADD is_draft BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_series ADD eligibility VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_series ADD schedule VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_series ADD organization_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_series ADD CONSTRAINT FK_5553C1FE32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5553C1FE32C8A3DE ON competition_series (organization_id)');
        $this->addSql('ALTER TABLE followed_competition ADD organization_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE followed_competition ADD CONSTRAINT FK_ED8E421532C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_ED8E421532C8A3DE ON followed_competition (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ED8E421599E6F5DF32C8A3DE ON followed_competition (player_id, organization_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event_url_redirect DROP CONSTRAINT FK_4C7FFA5132C8A3DE');
        $this->addSql('ALTER TABLE event_url_redirect DROP CONSTRAINT FK_4C7FFA515278319C');
        $this->addSql('ALTER TABLE event_url_redirect DROP CONSTRAINT FK_4C7FFA517B39D312');
        $this->addSql('ALTER TABLE event_url_redirect DROP CONSTRAINT FK_4C7FFA51A6005CA0');
        $this->addSql('ALTER TABLE organization DROP CONSTRAINT FK_C1EE637C3EDBBB76');
        $this->addSql('ALTER TABLE organization DROP CONSTRAINT FK_C1EE637C43132E94');
        $this->addSql('ALTER TABLE organization DROP CONSTRAINT FK_C1EE637CBC7FE91');
        $this->addSql('ALTER TABLE organization_maintainer DROP CONSTRAINT FK_E7FAACED32C8A3DE');
        $this->addSql('ALTER TABLE organization_maintainer DROP CONSTRAINT FK_E7FAACED99E6F5DF');
        $this->addSql('DROP TABLE event_url_redirect');
        $this->addSql('DROP TABLE organization');
        $this->addSql('DROP TABLE organization_maintainer');
        $this->addSql('ALTER TABLE competition DROP CONSTRAINT FK_B50A2CB132C8A3DE');
        $this->addSql('DROP INDEX IDX_B50A2CB132C8A3DE');
        $this->addSql('ALTER TABLE competition DROP is_draft');
        $this->addSql('ALTER TABLE competition DROP eligibility');
        $this->addSql('ALTER TABLE competition DROP organization_id');
        $this->addSql('ALTER TABLE competition_series DROP CONSTRAINT FK_5553C1FE32C8A3DE');
        $this->addSql('DROP INDEX IDX_5553C1FE32C8A3DE');
        $this->addSql('ALTER TABLE competition_series DROP is_draft');
        $this->addSql('ALTER TABLE competition_series DROP eligibility');
        $this->addSql('ALTER TABLE competition_series DROP schedule');
        $this->addSql('ALTER TABLE competition_series DROP organization_id');
        $this->addSql('ALTER TABLE followed_competition DROP CONSTRAINT FK_ED8E421532C8A3DE');
        $this->addSql('DROP INDEX IDX_ED8E421532C8A3DE');
        $this->addSql('DROP INDEX UNIQ_ED8E421599E6F5DF32C8A3DE');
        $this->addSql('ALTER TABLE followed_competition DROP organization_id');
    }
}
