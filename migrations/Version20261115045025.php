<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115045025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Data import: the distinct puzzles of an import, their pairing with the catalogue and the remembered decisions (stage 5a)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_import_puzzle (brand_text TEXT DEFAULT NULL, pieces_count INT DEFAULT NULL, eans JSONB DEFAULT \'[]\' NOT NULL, brand_codes JSONB DEFAULT \'[]\' NOT NULL, own_puzzle_id UUID DEFAULT NULL, rows_results INT DEFAULT 0 NOT NULL, rows_owned INT DEFAULT 0 NOT NULL, rows_wish_list INT DEFAULT 0 NOT NULL, tier VARCHAR(16) DEFAULT NULL, candidates JSONB DEFAULT \'[]\' NOT NULL, searched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, choice VARCHAR(16) DEFAULT \'undecided\' NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_how VARCHAR(16) DEFAULT NULL, decision_version INT DEFAULT 0 NOT NULL, id UUID NOT NULL, puzzle_key VARCHAR(80) NOT NULL, name TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, manufacturer_id UUID DEFAULT NULL, puzzle_id UUID DEFAULT NULL, import_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AFD571EDA23B42D ON data_import_puzzle (manufacturer_id)');
        $this->addSql('CREATE INDEX IDX_AFD571EDD9816812 ON data_import_puzzle (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_AFD571EDB6A263D9 ON data_import_puzzle (import_id)');
        $this->addSql('CREATE INDEX IDX_AFD571EDB6A263D9249E978AC1AB5A92 ON data_import_puzzle (import_id, tier, choice)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AFD571EDB6A263D91224331A ON data_import_puzzle (import_id, puzzle_key)');
        $this->addSql('CREATE TABLE import_puzzle_decision (choice VARCHAR(16) NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, puzzle_key VARCHAR(80) NOT NULL, puzzle_id UUID DEFAULT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F22A1A43D9816812 ON import_puzzle_decision (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_F22A1A4399E6F5DF ON import_puzzle_decision (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F22A1A4399E6F5DF1224331A ON import_puzzle_decision (player_id, puzzle_key)');
        $this->addSql('ALTER TABLE data_import_puzzle ADD CONSTRAINT FK_AFD571EDA23B42D FOREIGN KEY (manufacturer_id) REFERENCES manufacturer (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_puzzle ADD CONSTRAINT FK_AFD571EDD9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_puzzle ADD CONSTRAINT FK_AFD571EDB6A263D9 FOREIGN KEY (import_id) REFERENCES data_import (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_puzzle_decision ADD CONSTRAINT FK_F22A1A43D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_puzzle_decision ADD CONSTRAINT FK_F22A1A4399E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import ADD pairing_run INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import ADD paired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import ADD pairing_requeues INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE data_import ADD pairing_counters JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_row ADD already_yours_how VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE data_import_row ADD already_yours_solving_time_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_DF686C828440DCAE ON data_import_row (import_puzzle_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_puzzle DROP CONSTRAINT FK_AFD571EDA23B42D');
        $this->addSql('ALTER TABLE data_import_puzzle DROP CONSTRAINT FK_AFD571EDD9816812');
        $this->addSql('ALTER TABLE data_import_puzzle DROP CONSTRAINT FK_AFD571EDB6A263D9');
        $this->addSql('ALTER TABLE import_puzzle_decision DROP CONSTRAINT FK_F22A1A43D9816812');
        $this->addSql('ALTER TABLE import_puzzle_decision DROP CONSTRAINT FK_F22A1A4399E6F5DF');
        $this->addSql('DROP TABLE data_import_puzzle');
        $this->addSql('DROP TABLE import_puzzle_decision');
        $this->addSql('ALTER TABLE data_import DROP pairing_run');
        $this->addSql('ALTER TABLE data_import DROP paired_at');
        $this->addSql('ALTER TABLE data_import DROP pairing_requeues');
        $this->addSql('ALTER TABLE data_import DROP pairing_counters');
        $this->addSql('DROP INDEX IDX_DF686C828440DCAE');
        $this->addSql('ALTER TABLE data_import_row DROP already_yours_how');
        $this->addSql('ALTER TABLE data_import_row DROP already_yours_solving_time_id');
    }
}
