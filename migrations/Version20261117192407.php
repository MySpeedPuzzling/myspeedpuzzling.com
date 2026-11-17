<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Relays in the results import (docs/features/event-results-import/solo-and-relays.md §2): relay_result (a relay team's
 * total), puzzle_solving_time.relay_result_id (the team's result of each puzzle) and event_imported_result.leg (one row
 * per puzzle of a relay team). Generated; the foreign key index of relay_result_id is a partial custom one instead of
 * Doctrine's full one - the column is NULL on every row but a relay's (.claude/rules/database.md).
 */
final class Version20261117192407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relays: relay_result, puzzle_solving_time.relay_result_id, event_imported_result.leg';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE relay_result (id UUID NOT NULL, seconds INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('DROP INDEX uniq_9dd518408d0c9323d003210d');
        $this->addSql('ALTER TABLE event_imported_result ADD leg SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9DD518408D0C9323D003210D75D0804F ON event_imported_result (tab_id, team_key, leg)');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD relay_result_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT FK_FE83A93C7A17D902 FOREIGN KEY (relay_result_id) REFERENCES relay_result (id) NOT DEFERRABLE');
        // Instead of Doctrine's full IDX_FE83A93C7A17D902: NULL on every result but a relay's (docs/database-indexes.md)
        $this->addSql('CREATE INDEX custom_pst_fk_relay_result_id ON puzzle_solving_time (relay_result_id) WHERE relay_result_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT FK_FE83A93C7A17D902');
        $this->addSql('DROP INDEX custom_pst_fk_relay_result_id');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP relay_result_id');
        $this->addSql('DROP TABLE relay_result');
        $this->addSql('DROP INDEX UNIQ_9DD518408D0C9323D003210D75D0804F');
        $this->addSql('ALTER TABLE event_imported_result DROP leg');
        $this->addSql('CREATE UNIQUE INDEX uniq_9dd518408d0c9323d003210d ON event_imported_result (tab_id, team_key)');
    }
}
