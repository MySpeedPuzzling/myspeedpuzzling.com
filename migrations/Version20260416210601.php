<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416210601 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tier column to badge; enforce uniqueness per (player, type[, tier]) via partial indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE badge ADD tier SMALLINT DEFAULT NULL');

        // Partial unique indexes — one row per (player, type, tier) for tiered badges,
        // one row per (player, type) for single-tier badges like Supporter. Prefixed `custom_`
        // so CustomIndexFilteringSchemaManagerFactory skips them on introspection.
        $this->addSql('CREATE UNIQUE INDEX custom_badge_unique_tiered ON badge (player_id, type, tier) WHERE tier IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX custom_badge_unique_single_tier ON badge (player_id, type) WHERE tier IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_badge_unique_tiered');
        $this->addSql('DROP INDEX IF EXISTS custom_badge_unique_single_tier');
        $this->addSql('ALTER TABLE badge DROP tier');
    }
}
