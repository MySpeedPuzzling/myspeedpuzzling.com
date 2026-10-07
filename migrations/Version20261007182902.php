<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Time verification (docs/features/suspicious-time-review.md, "Data model"): the whole schema of the feature.
 */
final class Version20261007182902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Time verification: checks, cases, notices, decisions, references, confirmations; result_review_contact.suspicious_notice_ids';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE suspicious_time_case (status VARCHAR(255) NOT NULL, direction VARCHAR(255) DEFAULT NULL, tier VARCHAR(255) DEFAULT NULL, score DOUBLE PRECISION DEFAULT NULL, reasons JSONB DEFAULT \'[]\' NOT NULL, expected_seconds INT DEFAULT NULL, expected_source VARCHAR(255) DEFAULT NULL, detector_version SMALLINT DEFAULT NULL, last_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_by_id UUID DEFAULT NULL, reasons_shown JSONB DEFAULT \'[]\' NOT NULL, moderator_note TEXT DEFAULT NULL, marked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, player_edited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, origin VARCHAR(255) NOT NULL, fingerprint VARCHAR(32) NOT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, time_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_725B6E3F5EEADD3B ON suspicious_time_case (time_id)');
        $this->addSql('CREATE INDEX IDX_725B6E3F7B00651C3E4AD1B3 ON suspicious_time_case (status, direction)');
        $this->addSql('CREATE TABLE suspicious_time_check (version SMALLINT NOT NULL, outcome VARCHAR(255) NOT NULL, fingerprint VARCHAR(32) NOT NULL, checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, time_id UUID NOT NULL, PRIMARY KEY (time_id))');
        $this->addSql('CREATE TABLE suspicious_time_confirmation (id UUID NOT NULL, expected_seconds INT NOT NULL, confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, time_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F1E93BB85EEADD3B ON suspicious_time_confirmation (time_id)');
        $this->addSql('CREATE INDEX IDX_F1E93BB899E6F5DF ON suspicious_time_confirmation (player_id)');
        $this->addSql('CREATE TABLE suspicious_time_decision (id UUID NOT NULL, decision VARCHAR(255) NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, time_id UUID DEFAULT NULL, tracker_id UUID DEFAULT NULL, case_id UUID DEFAULT NULL, reasons_shown JSONB DEFAULT \'[]\' NOT NULL, note TEXT DEFAULT NULL, snapshot JSONB NOT NULL, decided_by_id UUID DEFAULT NULL, decided_by_name VARCHAR(255) DEFAULT NULL, decided_by_code VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_624E433457A5F592 ON suspicious_time_decision (decided_at)');
        $this->addSql('CREATE INDEX IDX_624E43345EEADD3B ON suspicious_time_decision (time_id)');
        $this->addSql('CREATE INDEX IDX_624E4334D9816812 ON suspicious_time_decision (puzzle_id)');
        $this->addSql('CREATE TABLE suspicious_time_notice (response VARCHAR(255) DEFAULT NULL, response_text TEXT DEFAULT NULL, responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, answer VARCHAR(255) DEFAULT NULL, answer_note TEXT DEFAULT NULL, answered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, contact_id UUID DEFAULT NULL, answer_contact_id UUID DEFAULT NULL, id UUID NOT NULL, marked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, notified_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, via VARCHAR(255) NOT NULL, case_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5766C716CF10D4F5 ON suspicious_time_notice (case_id)');
        $this->addSql('CREATE INDEX IDX_5766C71699E6F5DF ON suspicious_time_notice (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5766C716CF10D4F599E6F5DFE758F2C4 ON suspicious_time_notice (case_id, player_id, marked_at)');
        $this->addSql('CREATE TABLE suspicious_time_puzzle_confirmation (pieces_count INT NOT NULL, confirmed_by_id UUID DEFAULT NULL, confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (puzzle_id))');
        $this->addSql('CREATE TABLE suspicious_time_reference (pieces_range VARCHAR(16) NOT NULL, puzzling_type VARCHAR(16) NOT NULL, median_ppm DOUBLE PRECISION NOT NULL, p999_ppm DOUBLE PRECISION NOT NULL, sample_size INT NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (pieces_range, puzzling_type))');
        $this->addSql('ALTER TABLE suspicious_time_case ADD CONSTRAINT FK_725B6E3F5EEADD3B FOREIGN KEY (time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE suspicious_time_check ADD CONSTRAINT FK_8D8F16C05EEADD3B FOREIGN KEY (time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE suspicious_time_confirmation ADD CONSTRAINT FK_F1E93BB85EEADD3B FOREIGN KEY (time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE suspicious_time_confirmation ADD CONSTRAINT FK_F1E93BB899E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE suspicious_time_notice ADD CONSTRAINT FK_5766C716CF10D4F5 FOREIGN KEY (case_id) REFERENCES suspicious_time_case (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE suspicious_time_notice ADD CONSTRAINT FK_5766C71699E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE suspicious_time_puzzle_confirmation ADD CONSTRAINT FK_A04BFC2BD9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE result_review_contact ADD suspicious_notice_ids JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE suspicious_time_case DROP CONSTRAINT FK_725B6E3F5EEADD3B');
        $this->addSql('ALTER TABLE suspicious_time_check DROP CONSTRAINT FK_8D8F16C05EEADD3B');
        $this->addSql('ALTER TABLE suspicious_time_confirmation DROP CONSTRAINT FK_F1E93BB85EEADD3B');
        $this->addSql('ALTER TABLE suspicious_time_confirmation DROP CONSTRAINT FK_F1E93BB899E6F5DF');
        $this->addSql('ALTER TABLE suspicious_time_notice DROP CONSTRAINT FK_5766C716CF10D4F5');
        $this->addSql('ALTER TABLE suspicious_time_notice DROP CONSTRAINT FK_5766C71699E6F5DF');
        $this->addSql('ALTER TABLE suspicious_time_puzzle_confirmation DROP CONSTRAINT FK_A04BFC2BD9816812');
        $this->addSql('DROP TABLE suspicious_time_case');
        $this->addSql('DROP TABLE suspicious_time_check');
        $this->addSql('DROP TABLE suspicious_time_confirmation');
        $this->addSql('DROP TABLE suspicious_time_decision');
        $this->addSql('DROP TABLE suspicious_time_notice');
        $this->addSql('DROP TABLE suspicious_time_puzzle_confirmation');
        $this->addSql('DROP TABLE suspicious_time_reference');
        $this->addSql('ALTER TABLE result_review_contact DROP suspicious_notice_ids');
    }
}
