<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713131333 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'XP ledger table (xp_entry), player xp_total/level/experience_system_opted_out, pieces_count_snapshot on solving times + backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE xp_entry (id UUID NOT NULL, player_id UUID NOT NULL, amount INT NOT NULL, reason VARCHAR(255) NOT NULL, in_weekly_delta BOOLEAN NOT NULL, earned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, solving_time_id UUID DEFAULT NULL, badge_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E624552899E6F5DFC4DC16F ON xp_entry (player_id, earned_at)');
        $this->addSql('ALTER TABLE player ADD xp_total INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE player ADD level SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE player ADD experience_system_opted_out BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD pieces_count_snapshot INT DEFAULT NULL');

        // Idempotency anchors — custom_ prefix keeps them out of Doctrine schema introspection
        // (CustomIndexFilteringSchemaManagerFactory); both mirrored in tests/bootstrap.php.
        // A player gets each receipt-line reason at most once per solve (player_id is part of
        // the key because every team participant earns entries for the SAME solve);
        // compensations may repeat per solve.
        $this->addSql("CREATE UNIQUE INDEX custom_xp_entry_solve_reason ON xp_entry (player_id, solving_time_id, reason) WHERE solving_time_id IS NOT NULL AND reason != 'solve_compensation'");
        // One achievement XP entry per badge row, granted once forever.
        $this->addSql('CREATE UNIQUE INDEX custom_xp_entry_badge ON xp_entry (badge_id) WHERE badge_id IS NOT NULL');

        // Snapshot "pieces at log time" for all existing solves = current puzzle values (backfill decision).
        $this->addSql('UPDATE puzzle_solving_time SET pieces_count_snapshot = p.pieces_count FROM puzzle p WHERE p.id = puzzle_solving_time.puzzle_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE xp_entry');
        $this->addSql('ALTER TABLE player DROP xp_total');
        $this->addSql('ALTER TABLE player DROP level');
        $this->addSql('ALTER TABLE player DROP experience_system_opted_out');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP pieces_count_snapshot');
    }
}
