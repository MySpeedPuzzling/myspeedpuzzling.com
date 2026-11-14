<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114210838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_import (status VARCHAR(16) NOT NULL, file_sha256 VARCHAR(64) DEFAULT NULL, file_removed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, google_spreadsheet_id VARCHAR(100) DEFAULT NULL, version INT NOT NULL, read_error TEXT DEFAULT NULL, uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, discarded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cells_wiped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, source VARCHAR(16) NOT NULL, file_name TEXT NOT NULL, file_kind VARCHAR(8) DEFAULT NULL, file_size BIGINT DEFAULT NULL, file_key TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FDB0FF0499E6F5DF ON data_import (player_id)');
        $this->addSql('CREATE INDEX IDX_FDB0FF0499E6F5DF8B8E8428 ON data_import (player_id, created_at)');
        $this->addSql('CREATE TABLE data_import_row (import_puzzle_id UUID DEFAULT NULL, source_row_id TEXT DEFAULT NULL, cells JSONB DEFAULT NULL, parsed JSONB DEFAULT NULL, problems JSONB DEFAULT NULL, answers JSONB DEFAULT NULL, kind VARCHAR(16) DEFAULT NULL, source_key VARCHAR(80) DEFAULT NULL, add_as_separate BOOLEAN NOT NULL, state VARCHAR(16) NOT NULL, failure TEXT DEFAULT NULL, batch_id UUID DEFAULT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, row_number INT NOT NULL, first_seen_version INT NOT NULL, last_seen_version INT NOT NULL, same_as_row_id UUID DEFAULT NULL, import_id UUID NOT NULL, sheet_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DF686C82C58B3440 ON data_import_row (same_as_row_id)');
        $this->addSql('CREATE INDEX IDX_DF686C82B6A263D9 ON data_import_row (import_id)');
        $this->addSql('CREATE INDEX IDX_DF686C828B1206A5 ON data_import_row (sheet_id)');
        $this->addSql('CREATE INDEX IDX_DF686C828B1206A5C6B6CC86 ON data_import_row (sheet_id, row_number)');
        $this->addSql('CREATE INDEX IDX_DF686C82B6A263D9A393D2FB ON data_import_row (import_id, state)');
        $this->addSql('CREATE TABLE data_import_sheet (google_gid VARCHAR(100) DEFAULT NULL, included BOOLEAN NOT NULL, kind VARCHAR(16) DEFAULT NULL, region TEXT DEFAULT NULL, header_row INT DEFAULT NULL, header_signature VARCHAR(80) DEFAULT NULL, row_meaning VARCHAR(16) DEFAULT NULL, mapping JSONB DEFAULT NULL, options JSONB DEFAULT NULL, value_answers JSONB DEFAULT NULL, rows_total INT NOT NULL, rows_left_out INT NOT NULL, id UUID NOT NULL, position INT NOT NULL, title TEXT NOT NULL, import_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FC9F56FDB6A263D9 ON data_import_sheet (import_id)');
        $this->addSql('CREATE INDEX IDX_FC9F56FDB6A263D9462CE4F5 ON data_import_sheet (import_id, position)');
        $this->addSql('ALTER TABLE data_import ADD CONSTRAINT FK_FDB0FF0499E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_row ADD CONSTRAINT FK_DF686C82C58B3440 FOREIGN KEY (same_as_row_id) REFERENCES data_import_row (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_row ADD CONSTRAINT FK_DF686C82B6A263D9 FOREIGN KEY (import_id) REFERENCES data_import (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_row ADD CONSTRAINT FK_DF686C828B1206A5 FOREIGN KEY (sheet_id) REFERENCES data_import_sheet (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_sheet ADD CONSTRAINT FK_FC9F56FDB6A263D9 FOREIGN KEY (import_id) REFERENCES data_import (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import DROP CONSTRAINT FK_FDB0FF0499E6F5DF');
        $this->addSql('ALTER TABLE data_import_row DROP CONSTRAINT FK_DF686C82C58B3440');
        $this->addSql('ALTER TABLE data_import_row DROP CONSTRAINT FK_DF686C82B6A263D9');
        $this->addSql('ALTER TABLE data_import_row DROP CONSTRAINT FK_DF686C828B1206A5');
        $this->addSql('ALTER TABLE data_import_sheet DROP CONSTRAINT FK_FC9F56FDB6A263D9');
        $this->addSql('DROP TABLE data_import');
        $this->addSql('DROP TABLE data_import_row');
        $this->addSql('DROP TABLE data_import_sheet');
    }
}
