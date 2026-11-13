<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112195657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Organizer results sheets, their tabs and the results imported from them (docs/features/event-results-import)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_imported_result (id UUID NOT NULL, team_key VARCHAR(255) NOT NULL, tab_id UUID NOT NULL, solving_time_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9DD518408D0C9323 ON event_imported_result (tab_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9DD51840D6B05C60 ON event_imported_result (solving_time_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9DD518408D0C9323D003210D ON event_imported_result (tab_id, team_key)');
        $this->addSql('CREATE TABLE event_results_sheet (last_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_fetch_error TEXT DEFAULT NULL, id UUID NOT NULL, spreadsheet_id VARCHAR(100) NOT NULL, timezone VARCHAR(64) NOT NULL, country_code VARCHAR(2) NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, acting_player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5DFA566C529D0E6 ON event_results_sheet (spreadsheet_id)');
        $this->addSql('CREATE INDEX IDX_C5DFA56632C8A3DE ON event_results_sheet (organization_id)');
        $this->addSql('CREATE INDEX IDX_C5DFA566DD089428 ON event_results_sheet (acting_player_id)');
        $this->addSql('CREATE TABLE event_results_sheet_tab (status VARCHAR(255) NOT NULL, problems JSONB NOT NULL, content_hash VARCHAR(40) DEFAULT NULL, first_imported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, gid VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, competition_id UUID DEFAULT NULL, sheet_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_12D8824D7B39D312 ON event_results_sheet_tab (competition_id)');
        $this->addSql('CREATE INDEX IDX_12D8824D8B1206A5 ON event_results_sheet_tab (sheet_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_12D8824D8B1206A54C397118 ON event_results_sheet_tab (sheet_id, gid)');
        $this->addSql('ALTER TABLE event_imported_result ADD CONSTRAINT FK_9DD518408D0C9323 FOREIGN KEY (tab_id) REFERENCES event_results_sheet_tab (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_imported_result ADD CONSTRAINT FK_9DD51840D6B05C60 FOREIGN KEY (solving_time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_results_sheet ADD CONSTRAINT FK_C5DFA56632C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_results_sheet ADD CONSTRAINT FK_C5DFA566DD089428 FOREIGN KEY (acting_player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_results_sheet_tab ADD CONSTRAINT FK_12D8824D7B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_results_sheet_tab ADD CONSTRAINT FK_12D8824D8B1206A5 FOREIGN KEY (sheet_id) REFERENCES event_results_sheet (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_imported_result DROP CONSTRAINT FK_9DD518408D0C9323');
        $this->addSql('ALTER TABLE event_imported_result DROP CONSTRAINT FK_9DD51840D6B05C60');
        $this->addSql('ALTER TABLE event_results_sheet DROP CONSTRAINT FK_C5DFA56632C8A3DE');
        $this->addSql('ALTER TABLE event_results_sheet DROP CONSTRAINT FK_C5DFA566DD089428');
        $this->addSql('ALTER TABLE event_results_sheet_tab DROP CONSTRAINT FK_12D8824D7B39D312');
        $this->addSql('ALTER TABLE event_results_sheet_tab DROP CONSTRAINT FK_12D8824D8B1206A5');
        $this->addSql('DROP TABLE event_imported_result');
        $this->addSql('DROP TABLE event_results_sheet');
        $this->addSql('DROP TABLE event_results_sheet_tab');
    }
}
