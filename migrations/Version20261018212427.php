<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018212427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insights 5a: puzzle picture tags (puzzle_tag, puzzle_tag_state, puzzle_tag_correction) and the box-text mismatch list (box_text_mismatch, box_text_mismatch_decision)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE box_text_mismatch (field VARCHAR(16) NOT NULL, value_key VARCHAR(255) NOT NULL, box_values JSONB NOT NULL, catalogue_value VARCHAR(255) DEFAULT NULL, evidence JSONB NOT NULL, analysis_received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (puzzle_id, field))');
        $this->addSql('CREATE INDEX IDX_D9CC515BD9816812 ON box_text_mismatch (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_D9CC515BA15A0EC ON box_text_mismatch (detected_at)');
        $this->addSql('CREATE TABLE box_text_mismatch_decision (id UUID NOT NULL, field VARCHAR(16) NOT NULL, value_key VARCHAR(255) NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, decided_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FD6B6FC3D9816812 ON box_text_mismatch_decision (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_FD6B6FC3E26B496B ON box_text_mismatch_decision (decided_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FD6B6FC3D98168125BF54558D9652145 ON box_text_mismatch_decision (puzzle_id, field, value_key)');
        $this->addSql('CREATE TABLE puzzle_tag (tag_key VARCHAR(100) NOT NULL, score DOUBLE PRECISION DEFAULT NULL, uncertain BOOLEAN DEFAULT false NOT NULL, source VARCHAR(16) NOT NULL, analysis_version VARCHAR(50) DEFAULT NULL, taxonomy_version VARCHAR(16) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (puzzle_id, tag_key))');
        $this->addSql('CREATE INDEX IDX_9B3D2F30D9816812 ON puzzle_tag (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_9B3D2F308B4E31AB ON puzzle_tag (tag_key)');
        $this->addSql('CREATE TABLE puzzle_tag_correction (id UUID NOT NULL, tag_key VARCHAR(100) NOT NULL, verdict VARCHAR(16) NOT NULL, note TEXT DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, taxonomy_version VARCHAR(16) NOT NULL, puzzle_id UUID NOT NULL, decided_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FA7ECDA3D9816812 ON puzzle_tag_correction (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_FA7ECDA3E26B496B ON puzzle_tag_correction (decided_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FA7ECDA3D98168128B4E31AB ON puzzle_tag_correction (puzzle_id, tag_key)');
        $this->addSql('CREATE TABLE puzzle_tag_state (analysis_signature VARCHAR(32) DEFAULT NULL, corrections_signature VARCHAR(32) DEFAULT NULL, written_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (puzzle_id))');
        $this->addSql('ALTER TABLE box_text_mismatch ADD CONSTRAINT FK_D9CC515BD9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE box_text_mismatch_decision ADD CONSTRAINT FK_FD6B6FC3D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE box_text_mismatch_decision ADD CONSTRAINT FK_FD6B6FC3E26B496B FOREIGN KEY (decided_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_tag ADD CONSTRAINT FK_9B3D2F30D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_tag_correction ADD CONSTRAINT FK_FA7ECDA3D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_tag_correction ADD CONSTRAINT FK_FA7ECDA3E26B496B FOREIGN KEY (decided_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_tag_state ADD CONSTRAINT FK_9D47F672D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE box_text_mismatch DROP CONSTRAINT FK_D9CC515BD9816812');
        $this->addSql('ALTER TABLE box_text_mismatch_decision DROP CONSTRAINT FK_FD6B6FC3D9816812');
        $this->addSql('ALTER TABLE box_text_mismatch_decision DROP CONSTRAINT FK_FD6B6FC3E26B496B');
        $this->addSql('ALTER TABLE puzzle_tag DROP CONSTRAINT FK_9B3D2F30D9816812');
        $this->addSql('ALTER TABLE puzzle_tag_correction DROP CONSTRAINT FK_FA7ECDA3D9816812');
        $this->addSql('ALTER TABLE puzzle_tag_correction DROP CONSTRAINT FK_FA7ECDA3E26B496B');
        $this->addSql('ALTER TABLE puzzle_tag_state DROP CONSTRAINT FK_9D47F672D9816812');
        $this->addSql('DROP TABLE box_text_mismatch');
        $this->addSql('DROP TABLE box_text_mismatch_decision');
        $this->addSql('DROP TABLE puzzle_tag');
        $this->addSql('DROP TABLE puzzle_tag_correction');
        $this->addSql('DROP TABLE puzzle_tag_state');
    }
}
