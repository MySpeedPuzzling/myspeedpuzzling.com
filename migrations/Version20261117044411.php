<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Competition toolkit P1: competitors' time proposals (competition_time_proposal), plus the two partial unique indexes
 * that back "one pending proposal per entry" (custom_*, docs/database-indexes.md, mirrored in tests/bootstrap.php).
 */
final class Version20261117044411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition toolkit P1: competition_time_proposal + one pending proposal per entry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE competition_time_proposal (status VARCHAR(16) NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_seconds INT DEFAULT NULL, decision_reason VARCHAR(32) DEFAULT NULL, id UUID NOT NULL, proposer_linked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, tapped_at_ms BIGINT NOT NULL, received_at_ms BIGINT NOT NULL, clock_offset_ms DOUBLE PRECISION DEFAULT NULL, clock_rtt_ms DOUBLE PRECISION DEFAULT NULL, stopwatch_state_hash VARCHAR(32) DEFAULT NULL, started_at_ms BIGINT NOT NULL, proposed_seconds INT NOT NULL, receipt_seconds INT NOT NULL, late_by_ms INT NOT NULL, clock_changed BOOLEAN NOT NULL, decided_by_player_id UUID DEFAULT NULL, round_id UUID NOT NULL, participant_round_id UUID DEFAULT NULL, team_id UUID DEFAULT NULL, proposed_by_participant_id UUID NOT NULL, proposed_by_player_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_ECB5151EA6005CA07B00651C ON competition_time_proposal (round_id, status)');
        $this->addSql('CREATE INDEX IDX_ECB5151E75902BE5 ON competition_time_proposal (decided_by_player_id)');
        $this->addSql('CREATE INDEX IDX_ECB5151EA6005CA0 ON competition_time_proposal (round_id)');
        $this->addSql('CREATE INDEX IDX_ECB5151EA841D95D ON competition_time_proposal (participant_round_id)');
        $this->addSql('CREATE INDEX IDX_ECB5151E296CD8AE ON competition_time_proposal (team_id)');
        $this->addSql('CREATE INDEX IDX_ECB5151EAA5000FD ON competition_time_proposal (proposed_by_participant_id)');
        $this->addSql('CREATE INDEX IDX_ECB5151E72B3D23B ON competition_time_proposal (proposed_by_player_id)');
        $this->addSql('ALTER TABLE competition_time_proposal ADD CONSTRAINT FK_ECB5151E75902BE5 FOREIGN KEY (decided_by_player_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_time_proposal ADD CONSTRAINT FK_ECB5151EA6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_time_proposal ADD CONSTRAINT FK_ECB5151EA841D95D FOREIGN KEY (participant_round_id) REFERENCES competition_participant_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_time_proposal ADD CONSTRAINT FK_ECB5151E296CD8AE FOREIGN KEY (team_id) REFERENCES competition_team (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_time_proposal ADD CONSTRAINT FK_ECB5151EAA5000FD FOREIGN KEY (proposed_by_participant_id) REFERENCES competition_participant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_time_proposal ADD CONSTRAINT FK_ECB5151E72B3D23B FOREIGN KEY (proposed_by_player_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        // One pending proposal per entry - the backstop of the check under the event's participants lock
        $this->addSql("CREATE UNIQUE INDEX custom_time_proposal_pending_solo ON competition_time_proposal (participant_round_id) WHERE status = 'pending'");
        $this->addSql("CREATE UNIQUE INDEX custom_time_proposal_pending_team ON competition_time_proposal (team_id) WHERE status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX custom_time_proposal_pending_solo');
        $this->addSql('DROP INDEX custom_time_proposal_pending_team');
        $this->addSql('ALTER TABLE competition_time_proposal DROP CONSTRAINT FK_ECB5151E75902BE5');
        $this->addSql('ALTER TABLE competition_time_proposal DROP CONSTRAINT FK_ECB5151EA6005CA0');
        $this->addSql('ALTER TABLE competition_time_proposal DROP CONSTRAINT FK_ECB5151EA841D95D');
        $this->addSql('ALTER TABLE competition_time_proposal DROP CONSTRAINT FK_ECB5151E296CD8AE');
        $this->addSql('ALTER TABLE competition_time_proposal DROP CONSTRAINT FK_ECB5151EAA5000FD');
        $this->addSql('ALTER TABLE competition_time_proposal DROP CONSTRAINT FK_ECB5151E72B3D23B');
        $this->addSql('DROP TABLE competition_time_proposal');
    }
}
