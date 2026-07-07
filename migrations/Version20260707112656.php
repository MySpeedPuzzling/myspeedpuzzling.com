<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707112656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE competition_page_section (id UUID NOT NULL, type VARCHAR(255) NOT NULL, position INT NOT NULL, title VARCHAR(255) DEFAULT NULL, content JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, visible BOOLEAN DEFAULT true NOT NULL, competition_id UUID DEFAULT NULL, series_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_60866CEE7B39D312 ON competition_page_section (competition_id)');
        $this->addSql('CREATE INDEX IDX_60866CEE5278319C ON competition_page_section (series_id)');
        $this->addSql('CREATE TABLE round_result (updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, claim_created_solving_time BOOLEAN DEFAULT false NOT NULL, id UUID NOT NULL, seconds_to_solve INT DEFAULT NULL, missing_pieces INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, solving_time_id UUID DEFAULT NULL, round_id UUID NOT NULL, participant_id UUID DEFAULT NULL, team_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D7DDF8BFD6B05C60 ON round_result (solving_time_id)');
        $this->addSql('CREATE INDEX IDX_D7DDF8BFA6005CA0 ON round_result (round_id)');
        $this->addSql('CREATE INDEX IDX_D7DDF8BF9D1C3019 ON round_result (participant_id)');
        $this->addSql('CREATE INDEX IDX_D7DDF8BF296CD8AE ON round_result (team_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D7DDF8BFA6005CA09D1C3019 ON round_result (round_id, participant_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D7DDF8BFA6005CA0296CD8AE ON round_result (round_id, team_id)');
        $this->addSql('ALTER TABLE competition_page_section ADD CONSTRAINT FK_60866CEE7B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_page_section ADD CONSTRAINT FK_60866CEE5278319C FOREIGN KEY (series_id) REFERENCES competition_series (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE round_result ADD CONSTRAINT FK_D7DDF8BFD6B05C60 FOREIGN KEY (solving_time_id) REFERENCES puzzle_solving_time (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE round_result ADD CONSTRAINT FK_D7DDF8BFA6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE round_result ADD CONSTRAINT FK_D7DDF8BF9D1C3019 FOREIGN KEY (participant_id) REFERENCES competition_participant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE round_result ADD CONSTRAINT FK_D7DDF8BF296CD8AE FOREIGN KEY (team_id) REFERENCES competition_team (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition ADD registration_managed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition ADD capacity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD registration_opens_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD registration_closes_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD entry_fee_text VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD payment_instructions TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD page_layout JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD registration_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD registered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD checked_in_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD organizer_note VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_round ADD results_published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_series ADD page_layout JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_page_section DROP CONSTRAINT FK_60866CEE7B39D312');
        $this->addSql('ALTER TABLE competition_page_section DROP CONSTRAINT FK_60866CEE5278319C');
        $this->addSql('ALTER TABLE round_result DROP CONSTRAINT FK_D7DDF8BFD6B05C60');
        $this->addSql('ALTER TABLE round_result DROP CONSTRAINT FK_D7DDF8BFA6005CA0');
        $this->addSql('ALTER TABLE round_result DROP CONSTRAINT FK_D7DDF8BF9D1C3019');
        $this->addSql('ALTER TABLE round_result DROP CONSTRAINT FK_D7DDF8BF296CD8AE');
        $this->addSql('DROP TABLE competition_page_section');
        $this->addSql('DROP TABLE round_result');
        $this->addSql('ALTER TABLE competition DROP registration_managed');
        $this->addSql('ALTER TABLE competition DROP capacity');
        $this->addSql('ALTER TABLE competition DROP registration_opens_at');
        $this->addSql('ALTER TABLE competition DROP registration_closes_at');
        $this->addSql('ALTER TABLE competition DROP entry_fee_text');
        $this->addSql('ALTER TABLE competition DROP payment_instructions');
        $this->addSql('ALTER TABLE competition DROP page_layout');
        $this->addSql('ALTER TABLE competition_participant DROP registration_status');
        $this->addSql('ALTER TABLE competition_participant DROP registered_at');
        $this->addSql('ALTER TABLE competition_participant DROP paid_at');
        $this->addSql('ALTER TABLE competition_participant DROP checked_in_at');
        $this->addSql('ALTER TABLE competition_participant DROP organizer_note');
        $this->addSql('ALTER TABLE competition_round DROP results_published_at');
        $this->addSql('ALTER TABLE competition_series DROP page_layout');
    }
}
