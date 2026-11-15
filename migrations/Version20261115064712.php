<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115064712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_import_batch (state VARCHAR(16) NOT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rows_added INT DEFAULT 0 NOT NULL, rows_already_yours INT DEFAULT 0 NOT NULL, rows_refused INT DEFAULT 0 NOT NULL, rows_failed INT DEFAULT 0 NOT NULL, results_added INT DEFAULT 0 NOT NULL, collection_items_added INT DEFAULT 0 NOT NULL, wish_list_items_added INT DEFAULT 0 NOT NULL, settled_up_to UUID DEFAULT NULL, settled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, turn INT DEFAULT 1 NOT NULL, followed_up_to UUID DEFAULT NULL, followed_up_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, predictions_owed BOOLEAN DEFAULT false NOT NULL, ended_by_undo BOOLEAN DEFAULT false NOT NULL, notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, undone_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, request_id UUID NOT NULL, rows_requested INT NOT NULL, skipped JSONB NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, import_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_83A895CB427EB8A5 ON data_import_batch (request_id)');
        $this->addSql('CREATE INDEX IDX_83A895CBB6A263D9 ON data_import_batch (import_id)');
        $this->addSql('CREATE INDEX IDX_83A895CBB6A263D9D46F4E3 ON data_import_batch (import_id, started_at)');
        $this->addSql('CREATE INDEX IDX_83A895CBA393D2FB149885F6 ON data_import_batch (state, last_activity_at)');
        $this->addSql('CREATE TABLE data_import_settings (adding_paused BOOLEAN DEFAULT false NOT NULL, adding_paused_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, adding_paused_changed_by UUID DEFAULT NULL, adding_paused_reason TEXT DEFAULT NULL, id SMALLINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE data_import_undo (state VARCHAR(16) NOT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, turn INT DEFAULT 1 NOT NULL, rows_undone INT DEFAULT 0 NOT NULL, results_deleted INT DEFAULT 0 NOT NULL, results_kept_edited INT DEFAULT 0 NOT NULL, results_deleted_edited INT DEFAULT 0 NOT NULL, collection_items_removed INT DEFAULT 0 NOT NULL, collection_items_kept INT DEFAULT 0 NOT NULL, wish_list_items_removed INT DEFAULT 0 NOT NULL, wish_list_items_restored INT DEFAULT 0 NOT NULL, rows_failed INT DEFAULT 0 NOT NULL, id UUID NOT NULL, request_id UUID NOT NULL, scope VARCHAR(8) NOT NULL, batch_id UUID DEFAULT NULL, rows_requested INT NOT NULL, requested_by_player_id UUID NOT NULL, by_admin BOOLEAN NOT NULL, include_edited BOOLEAN NOT NULL, reason TEXT DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, import_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F19C0A38427EB8A5 ON data_import_undo (request_id)');
        $this->addSql('CREATE INDEX IDX_F19C0A38B6A263D9 ON data_import_undo (import_id)');
        $this->addSql('CREATE INDEX IDX_F19C0A38B6A263D9D46F4E3 ON data_import_undo (import_id, started_at)');
        $this->addSql('CREATE INDEX IDX_F19C0A38A393D2FB149885F6 ON data_import_undo (state, last_activity_at)');
        $this->addSql('ALTER TABLE data_import_batch ADD CONSTRAINT FK_83A895CBB6A263D9 FOREIGN KEY (import_id) REFERENCES data_import (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_undo ADD CONSTRAINT FK_F19C0A38B6A263D9 FOREIGN KEY (import_id) REFERENCES data_import (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_row ADD collection_item_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_row ADD wish_list_item_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_row ADD made JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_row ADD result_made BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE data_import_row ADD undo_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_DF686C82F39EBE7AA393D2FB ON data_import_row (batch_id, state)');
        $this->addSql('CREATE INDEX IDX_DF686C8287356422 ON data_import_row (undo_id)');
        $this->addSql('ALTER TABLE notification ADD target_data_import_batch_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD target_data_import_undo_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA45B56D34 FOREIGN KEY (target_data_import_batch_id) REFERENCES data_import_batch (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CACACB171D FOREIGN KEY (target_data_import_undo_id) REFERENCES data_import_undo (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA45B56D34 ON notification (target_data_import_batch_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CACACB171D ON notification (target_data_import_undo_id)');
        $this->addSql('ALTER TABLE player ADD import_emails BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_batch DROP CONSTRAINT FK_83A895CBB6A263D9');
        $this->addSql('ALTER TABLE data_import_undo DROP CONSTRAINT FK_F19C0A38B6A263D9');
        $this->addSql('DROP TABLE data_import_batch');
        $this->addSql('DROP TABLE data_import_settings');
        $this->addSql('DROP TABLE data_import_undo');
        $this->addSql('DROP INDEX IDX_DF686C82F39EBE7AA393D2FB');
        $this->addSql('DROP INDEX IDX_DF686C8287356422');
        $this->addSql('ALTER TABLE data_import_row DROP collection_item_id');
        $this->addSql('ALTER TABLE data_import_row DROP wish_list_item_id');
        $this->addSql('ALTER TABLE data_import_row DROP made');
        $this->addSql('ALTER TABLE data_import_row DROP result_made');
        $this->addSql('ALTER TABLE data_import_row DROP undo_id');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA45B56D34');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CACACB171D');
        $this->addSql('DROP INDEX IDX_BF5476CA45B56D34');
        $this->addSql('DROP INDEX IDX_BF5476CACACB171D');
        $this->addSql('ALTER TABLE notification DROP target_data_import_batch_id');
        $this->addSql('ALTER TABLE notification DROP target_data_import_undo_id');
        $this->addSql('ALTER TABLE player DROP import_emails');
    }
}
