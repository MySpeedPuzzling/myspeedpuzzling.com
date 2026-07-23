<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713175512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Denormalized player.achievement_points (+backfill) and leaderboard indexes: player totals, xp_entry solve lookups, weekly-delta window';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player ADD achievement_points INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX IDX_98197A65F2A2906 ON player (xp_total)');
        $this->addSql('CREATE INDEX IDX_98197A65C0ADC3B4 ON player (achievement_points)');
        $this->addSql('CREATE INDEX IDX_E6245528D6B05C60 ON xp_entry (solving_time_id)');

        // Weekly-delta leaderboard: index-only scan of the current ISO-week slice instead of
        // a growing whole-table scan (custom_ prefix => ignored by Doctrine introspection,
        // mirrored in tests/bootstrap.php).
        $this->addSql('CREATE INDEX custom_xp_entry_weekly_delta ON xp_entry (earned_at, player_id, amount) WHERE in_weekly_delta = true');

        // Backfill AP for players who already hold badges (prod: admin-granted Supporters).
        // Values mirror BadgeTier::points() / SINGLE_TIER_POINTS.
        $this->addSql(<<<'SQL'
UPDATE player SET achievement_points = totals.points
FROM (
    SELECT player_id, SUM(
        CASE tier
            WHEN 1 THEN 5
            WHEN 2 THEN 10
            WHEN 3 THEN 25
            WHEN 4 THEN 50
            WHEN 5 THEN 100
            ELSE 25
        END) AS points
    FROM badge
    GROUP BY player_id
) totals
WHERE totals.player_id = player.id
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX custom_xp_entry_weekly_delta');
        $this->addSql('DROP INDEX IDX_E6245528D6B05C60');
        $this->addSql('DROP INDEX IDX_98197A65C0ADC3B4');
        $this->addSql('DROP INDEX IDX_98197A65F2A2906');
        $this->addSql('ALTER TABLE player DROP achievement_points');
    }
}
