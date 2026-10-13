<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013111419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE online_entry (active_device_id VARCHAR(64) DEFAULT NULL, ready_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, clock_offset_ms DOUBLE PRECISION DEFAULT NULL, clock_rtt_ms DOUBLE PRECISION DEFAULT NULL, confirmed_moment_ms BIGINT DEFAULT NULL, confirmed_source VARCHAR(16) DEFAULT NULL, reported_measure VARCHAR(16) DEFAULT NULL, reported_pieces INT DEFAULT NULL, review_status VARCHAR(16) NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, flags JSONB DEFAULT \'[]\' NOT NULL, id UUID NOT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reviewed_by_id UUID DEFAULT NULL, entry_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_149E5AADFC6B21F1 ON online_entry (reviewed_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_149E5AADBA364942 ON online_entry (entry_id)');
        $this->addSql('CREATE TABLE online_entry_event (id UUID NOT NULL, device_id VARCHAR(64) NOT NULL, type VARCHAR(32) NOT NULL, client_ms BIGINT NOT NULL, perf_ms DOUBLE PRECISION DEFAULT NULL, offset_ms DOUBLE PRECISION DEFAULT NULL, rtt_ms DOUBLE PRECISION DEFAULT NULL, received_at_ms BIGINT NOT NULL, payload JSONB DEFAULT \'{}\' NOT NULL, online_entry_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2007AE5AE002008A ON online_entry_event (online_entry_id)');
        $this->addSql('CREATE INDEX IDX_2007AE5AE002008AFE5429E2 ON online_entry_event (online_entry_id, client_ms)');
        $this->addSql('CREATE TABLE online_entry_media (uploaded_at_ms BIGINT DEFAULT NULL, verified_bytes INT DEFAULT NULL, manifest JSONB DEFAULT NULL, verdict JSONB DEFAULT NULL, id UUID NOT NULL, kind VARCHAR(16) NOT NULL, taken_ms BIGINT NOT NULL, store VARCHAR(16) NOT NULL, object_key VARCHAR(255) NOT NULL, content_type VARCHAR(64) NOT NULL, declared_bytes INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, related_event_id UUID DEFAULT NULL, online_entry_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_718505F1E002008A ON online_entry_media (online_entry_id)');
        $this->addSql('CREATE INDEX IDX_718505F1E002008A3BC4BCD9F65EDC27 ON online_entry_media (online_entry_id, kind, taken_ms)');
        $this->addSql('CREATE TABLE online_round (id UUID NOT NULL, enabled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, detection_mode VARCHAR(16) NOT NULL, video_enabled BOOLEAN NOT NULL, participants_see_each_other BOOLEAN NOT NULL, studio_enabled BOOLEAN NOT NULL, recording_enabled BOOLEAN NOT NULL, unfinished_measure VARCHAR(16) NOT NULL, missing_piece_rule VARCHAR(32) NOT NULL, round_id UUID NOT NULL, enabled_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FA512DE9A6005CA0 ON online_round (round_id)');
        $this->addSql('CREATE INDEX IDX_FA512DE9553BD4CE ON online_round (enabled_by_id)');
        $this->addSql('ALTER TABLE online_entry ADD CONSTRAINT FK_149E5AADFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE online_entry ADD CONSTRAINT FK_149E5AADBA364942 FOREIGN KEY (entry_id) REFERENCES competition_participant_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE online_entry_event ADD CONSTRAINT FK_2007AE5AE002008A FOREIGN KEY (online_entry_id) REFERENCES online_entry (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE online_entry_media ADD CONSTRAINT FK_718505F1E002008A FOREIGN KEY (online_entry_id) REFERENCES online_entry (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE online_round ADD CONSTRAINT FK_FA512DE9A6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE online_round ADD CONSTRAINT FK_FA512DE9553BD4CE FOREIGN KEY (enabled_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_participant ADD online_token VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD online_token_created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FF3EC46F608866E6 ON competition_participant (online_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE online_entry DROP CONSTRAINT FK_149E5AADFC6B21F1');
        $this->addSql('ALTER TABLE online_entry DROP CONSTRAINT FK_149E5AADBA364942');
        $this->addSql('ALTER TABLE online_entry_event DROP CONSTRAINT FK_2007AE5AE002008A');
        $this->addSql('ALTER TABLE online_entry_media DROP CONSTRAINT FK_718505F1E002008A');
        $this->addSql('ALTER TABLE online_round DROP CONSTRAINT FK_FA512DE9A6005CA0');
        $this->addSql('ALTER TABLE online_round DROP CONSTRAINT FK_FA512DE9553BD4CE');
        $this->addSql('DROP TABLE online_entry');
        $this->addSql('DROP TABLE online_entry_event');
        $this->addSql('DROP TABLE online_entry_media');
        $this->addSql('DROP TABLE online_round');
        $this->addSql('DROP INDEX UNIQ_FF3EC46F608866E6');
        $this->addSql('ALTER TABLE competition_participant DROP online_token');
        $this->addSql('ALTER TABLE competition_participant DROP online_token_created_at');
    }
}
