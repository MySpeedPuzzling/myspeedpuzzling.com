<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007155429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition management (PR #136 port): managed registration, page sections, official results on round entries (+ unique participant/round), seating table numbers, publishing, official-result notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE competition_page_section (id UUID NOT NULL, type VARCHAR(255) NOT NULL, position INT NOT NULL, title VARCHAR(255) DEFAULT NULL, content JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, visible BOOLEAN DEFAULT true NOT NULL, competition_id UUID DEFAULT NULL, series_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_60866CEE7B39D312 ON competition_page_section (competition_id)');
        $this->addSql('CREATE INDEX IDX_60866CEE5278319C ON competition_page_section (series_id)');
        $this->addSql('ALTER TABLE competition_page_section ADD CONSTRAINT FK_60866CEE7B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_page_section ADD CONSTRAINT FK_60866CEE5278319C FOREIGN KEY (series_id) REFERENCES competition_series (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition ADD registration_timezone VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD registration_managed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition ADD capacity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD registration_opens_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD registration_closes_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD entry_fee_text VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD payment_instructions TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD registration_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD registered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD checked_in_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant ADD organizer_note VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD result_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD result_pieces_placed INT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD result_did_not_start BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD result_entered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD qualified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD table_number SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD result_entered_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_participant_round ADD CONSTRAINT FK_48D4E438CC72CDE FOREIGN KEY (result_entered_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_48D4E438CC72CDE ON competition_participant_round (result_entered_by_id)');
        $this->addSql('CREATE UNIQUE INDEX competition_participant_round_unique ON competition_participant_round (participant_id, round_id)');
        $this->addSql('ALTER TABLE competition_round ADD results_published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_round ADD results_first_published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_round ADD table_numbers_off BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_team ADD result_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_team ADD result_pieces_placed INT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_team ADD result_did_not_start BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_team ADD result_entered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_team ADD qualified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_team ADD table_number SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_team ADD result_entered_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_team ADD CONSTRAINT FK_CAA3380D8CC72CDE FOREIGN KEY (result_entered_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_CAA3380D8CC72CDE ON competition_team (result_entered_by_id)');
        $this->addSql('ALTER TABLE notification ADD target_competition_round_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAFD44E32C FOREIGN KEY (target_competition_round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CAFD44E32C ON notification (target_competition_round_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_page_section DROP CONSTRAINT FK_60866CEE7B39D312');
        $this->addSql('ALTER TABLE competition_page_section DROP CONSTRAINT FK_60866CEE5278319C');
        $this->addSql('DROP TABLE competition_page_section');
        $this->addSql('ALTER TABLE competition DROP registration_timezone');
        $this->addSql('ALTER TABLE competition DROP registration_managed');
        $this->addSql('ALTER TABLE competition DROP capacity');
        $this->addSql('ALTER TABLE competition DROP registration_opens_at');
        $this->addSql('ALTER TABLE competition DROP registration_closes_at');
        $this->addSql('ALTER TABLE competition DROP entry_fee_text');
        $this->addSql('ALTER TABLE competition DROP payment_instructions');
        $this->addSql('ALTER TABLE competition_participant DROP registration_status');
        $this->addSql('ALTER TABLE competition_participant DROP registered_at');
        $this->addSql('ALTER TABLE competition_participant DROP paid_at');
        $this->addSql('ALTER TABLE competition_participant DROP checked_in_at');
        $this->addSql('ALTER TABLE competition_participant DROP organizer_note');
        $this->addSql('ALTER TABLE competition_participant_round DROP CONSTRAINT FK_48D4E438CC72CDE');
        $this->addSql('DROP INDEX IDX_48D4E438CC72CDE');
        $this->addSql('DROP INDEX competition_participant_round_unique');
        $this->addSql('ALTER TABLE competition_participant_round DROP result_seconds');
        $this->addSql('ALTER TABLE competition_participant_round DROP result_pieces_placed');
        $this->addSql('ALTER TABLE competition_participant_round DROP result_did_not_start');
        $this->addSql('ALTER TABLE competition_participant_round DROP result_entered_at');
        $this->addSql('ALTER TABLE competition_participant_round DROP qualified_at');
        $this->addSql('ALTER TABLE competition_participant_round DROP table_number');
        $this->addSql('ALTER TABLE competition_participant_round DROP result_entered_by_id');
        $this->addSql('ALTER TABLE competition_round DROP results_published_at');
        $this->addSql('ALTER TABLE competition_round DROP results_first_published_at');
        $this->addSql('ALTER TABLE competition_round DROP table_numbers_off');
        $this->addSql('ALTER TABLE competition_team DROP CONSTRAINT FK_CAA3380D8CC72CDE');
        $this->addSql('DROP INDEX IDX_CAA3380D8CC72CDE');
        $this->addSql('ALTER TABLE competition_team DROP result_seconds');
        $this->addSql('ALTER TABLE competition_team DROP result_pieces_placed');
        $this->addSql('ALTER TABLE competition_team DROP result_did_not_start');
        $this->addSql('ALTER TABLE competition_team DROP result_entered_at');
        $this->addSql('ALTER TABLE competition_team DROP qualified_at');
        $this->addSql('ALTER TABLE competition_team DROP table_number');
        $this->addSql('ALTER TABLE competition_team DROP result_entered_by_id');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAFD44E32C');
        $this->addSql('DROP INDEX IDX_BF5476CAFD44E32C');
        $this->addSql('ALTER TABLE notification DROP target_competition_round_id');
    }
}
