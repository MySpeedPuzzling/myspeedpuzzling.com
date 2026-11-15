<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115113243 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_import_person (name VARCHAR(200) NOT NULL, rows_count INT NOT NULL, example_row_ids JSONB DEFAULT \'[]\' NOT NULL, choice VARCHAR(8) NOT NULL, decided_how VARCHAR(16) NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, person_key VARCHAR(160) NOT NULL, written_as_player BOOLEAN NOT NULL, player_id UUID DEFAULT NULL, import_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B9AF7F4499E6F5DF ON data_import_person (player_id)');
        $this->addSql('CREATE INDEX IDX_B9AF7F44B6A263D9 ON data_import_person (import_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B9AF7F44B6A263D99DD6CB2 ON data_import_person (import_id, person_key)');
        $this->addSql('CREATE TABLE import_person_decision (choice VARCHAR(8) NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, person_key VARCHAR(160) NOT NULL, linked_player_id UUID DEFAULT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_323052258FE43AD4 ON import_person_decision (linked_player_id)');
        $this->addSql('CREATE INDEX IDX_3230522599E6F5DF ON import_person_decision (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3230522599E6F5DF9DD6CB2 ON import_person_decision (player_id, person_key)');
        $this->addSql('ALTER TABLE data_import_person ADD CONSTRAINT FK_B9AF7F4499E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_person ADD CONSTRAINT FK_B9AF7F44B6A263D9 FOREIGN KEY (import_id) REFERENCES data_import (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_person_decision ADD CONSTRAINT FK_323052258FE43AD4 FOREIGN KEY (linked_player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_person_decision ADD CONSTRAINT FK_3230522599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_batch ADD partner_results JSONB DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE data_import_undo ADD partner_player_ids JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_person DROP CONSTRAINT FK_B9AF7F4499E6F5DF');
        $this->addSql('ALTER TABLE data_import_person DROP CONSTRAINT FK_B9AF7F44B6A263D9');
        $this->addSql('ALTER TABLE import_person_decision DROP CONSTRAINT FK_323052258FE43AD4');
        $this->addSql('ALTER TABLE import_person_decision DROP CONSTRAINT FK_3230522599E6F5DF');
        $this->addSql('DROP TABLE data_import_person');
        $this->addSql('DROP TABLE import_person_decision');
        $this->addSql('ALTER TABLE data_import_batch DROP partner_results');
        $this->addSql('ALTER TABLE data_import_undo DROP partner_player_ids');
    }
}
