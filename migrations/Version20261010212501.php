<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Progression launch (docs/features/xp-levels/launch-2026-10.md):
 * - `xp_settings.full_formula_from` = the moment this migration runs on production = the deploy moment. Solves logged
 *   before it earn the history XP formula, solves logged after it the full one (XpFormulaCutoff).
 * - Achievements grant no XP: their ledger entries and the `badge_id` column (with `custom_xp_entry_badge`) go. The
 *   ledger does not exist on production yet, so these deletes only clean development databases - their XP totals
 *   are put right by `myspeedpuzzling:xp-backfill`, which the launch runs anyway.
 * - `xp_settings.launch_ready_at` = the launch switch, NULL until `myspeedpuzzling:xp-backfill` verified the history.
 * - The launch reveal's "seen" hint is replaced by the launch announcement modal's impression.
 */
final class Version20261010212501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'XP formula cutoff at the deploy moment; achievements grant no XP';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE xp_settings (id SMALLINT NOT NULL, full_formula_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, launch_ready_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        // Stored once, never changed: the deploy itself sets the cutoff (timestamps are stored in UTC)
        $this->addSql("INSERT INTO xp_settings (id, full_formula_from) VALUES (1, NOW() AT TIME ZONE 'UTC')");

        $this->addSql("DELETE FROM xp_entry WHERE reason = 'achievement'");
        $this->addSql('ALTER TABLE xp_entry DROP badge_id');

        $this->addSql("DELETE FROM dismissed_hint WHERE type = 'xp_launch_reveal'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE xp_settings');
        $this->addSql('ALTER TABLE xp_entry ADD badge_id UUID DEFAULT NULL');
    }
}
